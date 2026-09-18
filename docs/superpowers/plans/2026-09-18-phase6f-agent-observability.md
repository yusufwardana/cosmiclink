# Phase 6F Agent Observability Implementation Plan

**Goal:** Tenant-safe fleet visibility, bounded job recovery, outbound Agent resilience.

**Architecture:** Laravel owns policy, timestamps, leases, attempt fencing and events. Go reports bounded facts and executes only DISCOVER_ROUTER. PostgreSQL transactions remain authoritative. No new dependency.

**Tech stack:** Laravel 12, PHP 8.2+, PostgreSQL, existing Blade/CSS, Go standard library.

**Spec:** User's Phase 6F requirements and explicitly approved compatibility boundary in this conversation.

## Constraints

- Root for every path below: `C:\xampp\htdocs\cosmiclink-phase6f`.
- Base: `6473540ecd62848d69f8320dc6c4e53ddbe25c6f`.
- Heartbeat 30 seconds; ONLINE age <90; STALE age >=90 and <300; OFFLINE age >=300 or never seen.
- Lease 120 seconds, renewal 30 seconds, maximum 3 attempts. Validate positive durations, heartbeat < stale < offline, renewal < lease.
- Preserve frozen RouterOS provider. No writes, MANAGED transitions, inbound listeners, remote execution, automatic updates or persistent offline queue.
- Legacy phase-6e first attempts remain compatible. No legacy recovered claims. Missing attempt fencing is permissible only for an original legacy lease, never a recovered attempt.
- New credentials use indexed public token ID plus hashed secret. Existing hashes stay valid through an explicit legacy-only fallback. No implicit rotation.
- No checkpoint commit until every acceptance gate passes. No push.

## 1. Health, inventory, authentication

Files: `config\network_agents.php`, `app\Services\Network\NetworkAgentHealthService.php`, `app\Services\Network\NetworkAgentService.php`, `app\Models\NetworkAgent.php`, `app\Http\Controllers\AgentApiController.php`, new additive migration under `database\migrations`, `tests\Feature\Phase6FAgentHealthTest.php`.

- [ ] Add failing tests for exact 89/90/299/300-second boundaries, never-seen and server-time heartbeat; reject invalid thresholds.
- [ ] Test bounded version/runtime/platform/uptime and capability allowlists. Unknown metadata must never persist. Retain explicit legacy version support; reject malformed/unsupported versions for claims.
- [ ] Add centralized status/compatibility/eligibility methods. Health stays derived; a stored last-observed status is only an event-deduplication cursor.
- [ ] Add nullable unique token_id; enroll with token_id.secret, hash only secret. Legacy lookup queries only null token_id rows; reject malformed indexed tokens without falling back to scanning. Test existing credentials remain valid.
- [ ] Test capability eligibility at creation and claim; optional update does not block work.
- [ ] Run focused red/green cycle before next task.

## 2. Lease protocol, attempt history, recovery

Files: `app\Services\Network\NetworkAgentService.php`, `app\Models\NetworkAgentJob.php`, `app\Models\NetworkAgentJobAttempt.php`, `app\Models\NetworkAgentEvent.php`, additive migration, `routes\api.php`, `app\Http\Controllers\AgentApiController.php`, `app\Console\Commands\RecoverNetworkAgentJobs.php`, `tests\Feature\Phase6FAgentLeaseTest.php`.

- [ ] Test claim returns lease expiry, attempt and opaque lease identifier. Store lease ownership and lease-aware flag at claim time, not from later heartbeat state.
- [ ] Unique (job_id, attempt); serialize claim/renew/result/recover using consistent agent-then-job lock ordering. Preserve assigned-agent scheduling, not fleet failover.
- [ ] Renewal requires matching agent, tenant, RUNNING status, current fence and unexpired lease; use server time for last_progress_at.
- [ ] Results validate fence before terminal-state idempotency. Expired/older attempt returns safe 409 without persistence. Duplicate completed current attempt returns success without extra events/resources.
- [ ] Legacy no-fence result accepted only for original unexpired legacy lease; first-attempt duplicate handling cannot authorize an older recovered attempt.
- [ ] Recovery closes expired attempt, requeues below maximum, fails at maximum; null pre-migration leases get a deterministic claimed_at-based deadline. Recovery twice has no additional effects.
- [ ] Fixed failure-code/message catalog; never persist supplied exception/message text as diagnostics. Unknown provider codes map to DISCOVERY_FAILED.
- [ ] Events: enrollment, meaningful observed health transitions, recovery and job lifecycle only. Heartbeat does not create events when state is unchanged. Capture missed recency transition before updating last_seen_at on return.
- [ ] Test concurrent claim/recovery, cross-agent same-tenant and cross-tenant rejection, legacy upgrade/retry restriction and raw secret absence.
- [ ] Recovery command also reconciles observed health transitions. Existing routes/console.php has no scheduler pattern: document external periodic command unless a schedule is explicitly introduced and tested.

## 3. Fleet and detail UI

Files: `app\Services\Network\NetworkAgentFleetService.php`, `app\Http\Controllers\NetworkAgentController.php`, `routes\web.php`, `resources\views\network\agents.blade.php`, `resources\views\network\agent.blade.php`, `tests\Feature\Phase6FAgentFleetTest.php`.

- [ ] Test tenant-scoped counts and detail 404 for foreign tenant, including events and routers.
- [ ] Aggregate real counts, paginate agents, bound recent jobs/events. Calculate durations from server timestamps.
- [ ] Reuse existing panel, table and status classes. Show status/compatibility/platform/capabilities/current job/recent failure; detail includes timestamps, attempts and history.
- [ ] Assert absence of hash/token/password/raw credential objects in HTML and JSON. No frontend redesign.


## 4. Go outbound lifecycle

Files: `network-engine\internal\agent\agent.go`, `network-engine\internal\agent\agent_test.go`, new focused loop tests, `network-engine\cmd\agent\main.go`.

- [ ] Test semantic version, runtime version, OS, architecture and readonly/lease capabilities in heartbeat.
- [ ] Renew fenced lease every 30 seconds during discovery; maintain separate fleet heartbeat. Cancel on lost lease; never submit after cancellation.
- [ ] Bounded request timeout, exponential jittered backoff, reset on success; permanent authentication failure exits safely. Test Core outage, timeout, cancellation, graceful shutdown and safe logging.
- [ ] Preserve discovery-only dispatch and frozen provider tests.

## 5. Acceptance and checkpoint

Files: runnable harness under `tests\Acceptance`, `docs\NETWORK_AGENT.md`.

- [ ] Build real executable outside tracked tree. Verify actual test database before setup; retain exact result in memory for replay without committing credential captures.
- [ ] Healthy HTTP flow: heartbeat, ONLINE, claim/lease, fake discovery, success.
- [ ] Disappearance: local controlled proxy holds result/renewal after real claim; stop executable, allow actual lease expiration, run recovery. No manual SQL status edits. Verify requeue and attempt/event history.
- [ ] Restart executable, observe recovered ONLINE transition, complete recovered attempt. Accelerated valid thresholds permitted only in acceptance configuration.
- [ ] Replay exact successful request and rerun recovery: unchanged side-effect counts. Reject captured older-attempt result.
- [ ] Audit raw secret literals against DB serialization and logs without printing them. Assert zero changes in network operations and MANAGED counts.
- [ ] Run focused tests, explicit 6B/6C/6D/6E regressions, full Laravel suite, gofmt check, go test ./..., go vet ./..., Pint, npm build, git diff --check. Capture each exit code immediately.
- [ ] Verify provider hash unchanged from base; review every file, clean owned artifacts/processes. Commit only after complete acceptance, without bypassing hooks. No push.

## Baseline evidence

## Execution evidence — September 18, 2026

Recovered from the actual worktree and existing failing specifications, not the
compacted conversation. Completed health/inventory/indexed enrollment, bounded
heartbeat, fenced leases/renewal/recovery, attempt/event history, tenant fleet
and detail UI, Go resilience, and real-executable acceptance.

Implementation refinements: attempt/event history uses bounded query-builder
access rather than unnecessary Eloquent models; no NetworkAgentEvent model was
present to preserve. Claim locks agent then job; renewal/result/recovery lock job
only and never acquire an agent lock afterward, avoiding reverse lock ordering.
Recovery separately reconciles agents before processing jobs. Added and inspected
the every-minute scheduler registration. Agent pages paginate 50 entries.

Acceptance includes real Go executable healthy/disappearance/restart flows and
separate concurrent PostgreSQL claim/recovery process probes. Captured old results
return 409 before persistence; current replay and repeated recovery leave counts
unchanged. Two snapshots, eight resources, two audits, three execution attempts;
zero network operation rows and zero MANAGED resources. Secret literals absent
from inspected persistence and application/process logs. Provider unchanged.

Final Laravel suite: 104 tests total, 101 passed, 3 live-only skipped, 507 assertions,
no warnings after adding an ignored comment-only local .env. Live 6B (2 tests,
19 assertions) and live 6C (6 tests, 43 assertions) pass separately with fresh
Fake Engine processes. The initial combined live run exposed shared mutation-counter
state, resolved in the harness without changing frozen regression tests.
Focused Agent suite: 17 tests, 147 assertions. Go test/vet, provider regression,
Pint (186 files), frontend build and whitespace checks pass. Original checkboxes
above are retained as design history; this section records actual execution.

Remaining intentional boundaries: no persistent offline queue, no automatic token
rotation/update, no history-pruning policy, no hardware acceptance (provider frozen).

Dedicated copied vendor directory and local Composer autoload avoid shared vendor writes. Existing node_modules reused through junction. Initial six Laravel view failures were missing Vite assets; build exit 0 resolved them. Laravel baseline exit 0: 90 warnings, 1 passed, 396 assertions. Retained log: `C:\xampp\htdocs\cosmiclink-phase6f\storage\logs\phase6f-baseline-tests.log`. Go go1.27.0 windows/amd64, test ./... exit 0. Implementation and acceptance remain pending.
