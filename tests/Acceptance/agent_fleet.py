"""Real executable, outbound HTTP acceptance. Secrets stay in process memory.

Run from the worktree: python tests/Acceptance/agent_fleet.py
Requires an already migrated isolated cosmiclink_test database and local Go/PHP.
No physical router, persistent queue, or production listener is involved.
"""
import http.server
from concurrent.futures import ThreadPoolExecutor
import json
import os
from pathlib import Path
import secrets
import shutil
import subprocess
import tempfile
import threading
import time
import urllib.error
import urllib.request
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[2]
ENV = os.environ.copy()
for entry in ET.parse(ROOT / 'phpunit.xml').findall('./php/env'):
    ENV[entry.attrib['name']] = entry.attrib['value']
ENV.update(NETWORK_AGENT_HEARTBEAT_SECONDS='1', NETWORK_AGENT_STALE_SECONDS='4',
           NETWORK_AGENT_OFFLINE_SECONDS='8', NETWORK_AGENT_LEASE_SECONDS='6',
           NETWORK_AGENT_RENEWAL_SECONDS='2', APP_DEBUG='false')
PHP = shutil.which('php')
GO = shutil.which('go') or r'C:\Program Files\Go\bin\go.exe'
CORE = 'http://127.0.0.1:18066'
PROXY = 'http://127.0.0.1:18067'
captured = []
claims = []
hold = threading.Event()
result_held = threading.Event()


def bridge(action, identity=None, **extra):
    payload = dict(identity or {}, action=action, **extra)
    process = subprocess.run([PHP, str(ROOT / 'tests/Acceptance/agent_fleet_bridge.php')],
                             input=json.dumps(payload), text=True, capture_output=True,
                             cwd=ROOT, env=ENV, timeout=20)
    if process.returncode:
        raise RuntimeError('Acceptance bridge failed (output withheld for secret safety)')
    return json.loads(process.stdout)


def request(path, token, payload):
    req = urllib.request.Request(CORE + path, data=json.dumps(payload).encode(),
                                 headers={'Authorization': 'Bearer ' + token,
                                          'Content-Type': 'application/json', 'Accept': 'application/json'})
    try:
        with urllib.request.urlopen(req, timeout=10) as response:
            return response.status, response.read()
    except urllib.error.HTTPError as error:
        return error.code, error.read()


class Proxy(http.server.BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def do_POST(self):
        data = self.rfile.read(int(self.headers.get('Content-Length', '0')))
        payload = json.loads(data)
        if self.path.endswith('/result'):
            captured.append((self.path, payload))
            if hold.is_set():
                result_held.set()
                self.send_response(503)
                self.end_headers()
                return
        if self.path.endswith('/renew') and hold.is_set():
            self.send_response(503)
            self.end_headers()
            return
        status, body = request(self.path, self.headers['Authorization'][7:], payload)
        if self.path.endswith('/claim') and status == 200:
            # Retain only lease identity, never the returned connection object.
            job = json.loads(body)['job']
            claims.append({key: job[key] for key in ['id', 'attempt', 'fence']})
        self.send_response(status)
        self.send_header('Content-Type', 'application/json')
        self.end_headers()
        self.wfile.write(body)


def wait_until(predicate, seconds=25):
    deadline = time.monotonic() + seconds
    while time.monotonic() < deadline:
        value = predicate()
        if value:
            return value
        time.sleep(.2)
    raise AssertionError('Acceptance condition timed out')


def stop(process):
    if process and process.poll() is None:
        process.terminate()
        try:
            process.wait(timeout=5)
        except subprocess.TimeoutExpired:
            process.kill()
            process.wait(timeout=5)


def main():
    identity = None
    core = agent = proxy = None
    router_secret = secrets.token_hex(24)
    with tempfile.TemporaryDirectory(prefix='cosmiclink-agent-acceptance-') as temporary:
        executable = Path(temporary) / 'agent.exe'
        subprocess.run([GO, 'build', '-o', str(executable), './cmd/agent'], cwd=ROOT / 'network-engine', check=True)
        with open(Path(temporary) / 'core.log', 'w+b') as core_log, open(Path(temporary) / 'agent.log', 'w+b') as agent_log:
            try:
                core = subprocess.Popen([PHP, '-S', '127.0.0.1:18066', '-t', 'public', 'public/index.php'], cwd=ROOT, env=ENV, stdout=core_log, stderr=core_log)
                time.sleep(1)
                assert core.poll() is None, 'Local Core did not start'
                identity = bridge('enroll', router_secret=router_secret)
                token = identity.pop('token')
                proxy = http.server.ThreadingHTTPServer(('127.0.0.1', 18067), Proxy)
                threading.Thread(target=proxy.serve_forever, daemon=True).start()
                agent_env = dict(ENV, COSMICLINK_CORE_URL=PROXY, COSMICLINK_AGENT_TOKEN=token, NETWORK_DISCOVERY_PROVIDER='fake', COSMICLINK_AGENT_ALLOW_DEV_HTTP='1')

                def start():
                    return subprocess.Popen([str(executable)], cwd=ROOT, env=agent_env, stdout=agent_log, stderr=agent_log)

                agent = start()
                wait_until(lambda: bridge('state', identity)['health'] == 'ONLINE')
                first = bridge('create', identity)
                assert first['status'] == 'PENDING'
                first_id = dict(identity, job=first['job'])
                wait_until(lambda: bridge('state', first_id)['job']['status'] == 'SUCCEEDED')
                healthy = bridge('state', first_id)
                assert healthy['network_discovery_snapshots'] == 1 and healthy['network_discovery_audits'] == 1
                assert healthy['discovered_network_resources'] > 0 and healthy['managed'] == 0

                hold.set()
                second = bridge('create', identity)
                second_id = dict(identity, job=second['job'])
                assert result_held.wait(15), 'Agent never submitted controlled interrupted result'
                interrupted = bridge('state', second_id)
                assert interrupted['job']['status'] == 'RUNNING' and interrupted['job']['attempt'] == 1
                old_path, old_result = captured[-1]
                stop(agent)
                time.sleep(9)
                assert bridge('state', second_id)['health'] == 'OFFLINE'

                def recover():
                    completed = subprocess.run([PHP, 'artisan', 'network-agents:recover-jobs'], cwd=ROOT, env=ENV, capture_output=True, timeout=20)
                    assert completed.returncode == 0, 'Recovery command failed'

                with ThreadPoolExecutor(max_workers=2) as pool:
                    list(pool.map(lambda _: recover(), range(2)))
                recovered = bridge('state', second_id)
                assert recovered['job']['status'] == 'PENDING' and 'JOB_REQUEUED' in recovered['events']
                recover()
                assert bridge('state', second_id)['network_agent_events'] == recovered['network_agent_events']
                hold.clear()
                agent = start()
                wait_until(lambda: bridge('state', second_id)['job']['status'] == 'SUCCEEDED')
                final = bridge('state', second_id)
                assert final['job']['attempt'] == 2 and final['job']['fence'] != interrupted['job']['fence']
                assert final['health'] == 'ONLINE' and 'AGENT_RECOVERED' in final['events']
                assert final['network_discovery_snapshots'] == 2 and final['network_discovery_audits'] == 2
                assert final['managed'] == 0 and final['network_operation_logs'] == 0
                assert len(claims) == 3
                assert request(old_path, token, old_result)[0] == 409
                path, successful = captured[-1]
                assert request(path, token, successful)[0] == 200
                recover()
                replay = bridge('state', second_id)
                for field in ['network_discovery_snapshots', 'discovered_network_resources', 'network_discovery_audits', 'network_agent_events', 'network_agent_job_attempts']:
                    assert replay[field] == final[field], 'Duplicate side effect: ' + field
                stop(agent)
                assert request('/api/v1/agent/heartbeat', token, {})[0] == 200
                race_job = bridge('create', identity)
                with ThreadPoolExecutor(max_workers=2) as pool:
                    race = list(pool.map(lambda _: bridge('claim', identity), range(2)))
                assert sum(item['claimed'] for item in race) == 1, 'Concurrent claim duplicated lease'
                assert bridge('state', dict(identity, job=race_job['job']))['job']['attempt'] == 1
                values = [token, token.split('.')[1], router_secret]
                assert bridge('audit', identity, secrets=values)['safe'], 'Database secret audit failed'
                for log in [core_log, agent_log]:
                    log.flush()
                    log.seek(0)
                    contents = log.read().decode(errors='replace')
                    assert not any(value in contents for value in values), 'Runtime log secret audit failed'
                print(json.dumps({'healthy': True, 'disappearance': True, 'recovery': True, 'stale_rejected': True, 'duplicate_safe': True, 'concurrent_claim_safe': True, 'concurrent_recovery_safe': True, 'secret_audit': True, 'snapshots': final['network_discovery_snapshots'], 'resources': final['discovered_network_resources'], 'audits': final['network_discovery_audits'], 'attempts': final['network_agent_job_attempts'], 'network_mutations': final['network_operation_logs'], 'managed': final['managed']}))
            finally:
                stop(agent)
                if proxy:
                    proxy.shutdown()
                    proxy.server_close()
                stop(core)
                if identity:
                    bridge('cleanup', identity)


if __name__ == '__main__':
    main()