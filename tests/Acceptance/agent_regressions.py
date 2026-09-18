"""Run existing live 6B/6C and full Laravel suite with an isolated fake Go Engine."""
from agent_fleet import ENV, GO, PHP, ROOT, stop
import os
from pathlib import Path
import secrets
import subprocess
import tempfile
import time


with tempfile.TemporaryDirectory(prefix='cosmiclink-regression-') as directory:
    executable = Path(directory) / 'engine.exe'
    subprocess.run([GO, 'build', '-o', str(executable), './cmd/server'], cwd=ROOT / 'network-engine', check=True)
    env = dict(os.environ, **ENV)
    env.update(NETWORK_ENGINE_LIVE_TEST='1', GO_NETWORK_ENGINE_TOKEN=secrets.token_hex(32),
               GO_NETWORK_ENGINE_ADDRESS='127.0.0.1:18787', GO_NETWORK_ENGINE_URL='http://127.0.0.1:18787',
               NETWORK_DISCOVERY_PROVIDER='fake')
    # Normal defaults for policy tests, not accelerated acceptance timing.
    for key in list(env):
        if key.startswith('NETWORK_AGENT_'):
            del env[key]
    process = None
    try:
        for label, arguments in [
            ('live-6b', ['artisan', 'test', '--filter=Phase6B']),
            ('live-6c', ['artisan', 'test', '--filter=Phase6C']),
            ('regression-6e', ['artisan', 'test', '--filter=Phase6E']),
            ('full-live', ['artisan', 'test']),
            ('full-phpunit', ['vendor/phpunit/phpunit/phpunit', '--display-warnings']),
        ]:
            stop(process)
            process = subprocess.Popen([str(executable)], env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            time.sleep(.8)
            assert process.poll() is None
            run_env = env.copy()
            if label.startswith('full-'):
                # Live tests have independent process-state prerequisites and
                # are exercised explicitly above, not sharing a mutation count.
                run_env.pop('NETWORK_ENGINE_LIVE_TEST', None)
            with open(ROOT / ('storage/logs/phase6f-' + label + '.log'), 'w', encoding='utf8') as output:
                result = subprocess.run([PHP] + arguments, cwd=ROOT, env=run_env, stdout=output, stderr=subprocess.STDOUT, timeout=180)
            (ROOT / ('storage/logs/phase6f-' + label + '.exit')).write_text(str(result.returncode))
            print(label + ': exit ' + str(result.returncode), flush=True)
            assert result.returncode == 0
    finally:
        stop(process)