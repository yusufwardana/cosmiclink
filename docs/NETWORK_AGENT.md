# CosmicLink Network Agent — Phase 6F

```text
CosmicLink Cloud (Laravel)
        ↓ HTTPS outbound polling initiated by the agent
CosmicLink Network Agent (Go, inside ISP/private LAN)
        ↓ RouterOS API/API-SSL
MikroTik RouterOS
```

Laravel remains the business brain: it owns tenant identity, agent authorization, router ownership, job creation/status, normalized persistence, resources, snapshots, audits, and operator-visible state. The Go agent only heartbeats, claims work assigned to its tenant identity, performs local read-only discovery, and submits a normalized result.

## Fleet policy (Phase 6F)

`config/network_agents.php` centralizes policy. Heartbeat defaults to 30 seconds;
ONLINE means server-recorded heartbeat age <90 seconds, STALE means >=90 and
<300, and OFFLINE means >=300 or never seen. Positive intervals and their order
are validated. **OFFLINE is Agent/Core communication health, not an ISP or
customer internet outage.** Agent-supplied timestamps never determine health.

Heartbeats accept only bounded version, Go runtime, OS, architecture, uptime,
and the explicit `discovery.routeros.readonly` and `jobs.lease.v1` capabilities.
Minimum version defaults to 0.5.0; recommended version to 0.6.0. Semantic version
precedence derives CURRENT / UPDATE_AVAILABLE / UNSUPPORTED. Optional updates
do not block work. Claims require ONLINE and readonly discovery capability.
Legacy `phase-6e` and `6e-test` version labels permit only first-attempt discovery.

## Indexed credentials and upgrade

New server-side enrollment returns `token_id.secret` once. A unique indexed
32-character random public ID selects one row; Laravel verifies the secret hash.
The raw secret is never stored. Existing rows with NULL token_id retain explicit
legacy hash lookup; indexed/malformed tokens never fall back to that scan.
No implicit rotation occurs. Operators can enroll a replacement identity and
retire the old enrollment after its jobs finish; automatic rotation is not provided.

## Leases, fencing and recovery

Claims atomically create a 120-second lease, increment attempt, generate a new
opaque fence and insert one durable attempt row. Lease-aware agents call
`POST /api/v1/agent/jobs/{job}/renew` every 30 seconds with attempt and fence.
Renewal requires the owning tenant/agent, RUNNING, current fence, unexpired lease.
Results check the fence **before** terminal-state idempotency or discovery
persistence. Late attempts receive 409 and cannot write snapshots/resources/audits.
Only original non-lease-aware first attempts may omit fencing fields.

`php artisan network-agents:recover-jobs` locks expired RUNNING jobs, marks the
attempt EXPIRED, invalidates its fence, and requeues eligible work. Maximum attempts
defaults to 3. Retries require version >=0.6.0 and `jobs.lease.v1`; legacy retries
fail with INCOMPATIBLE_AGENT_RETRY. Other recovery codes are AGENT_LEASE_EXPIRED
and MAX_ATTEMPTS_EXCEEDED. Re-running recovery is idempotent. Existing pre-lease
RUNNING rows are recovered as expired, never granted an unbounded lease.

The command is scheduled every minute using Laravel's existing scheduler facility;
deployment must run `schedule:run` or `schedule:work`. PostgreSQL locks and state
remain authoritative. The scheduler overlap guard is not business state.
Health transitions are reconciled by recovery and heartbeat, without per-heartbeat
events. Fleet/detail pages are tenant-scoped and show bounded recent jobs,
attempts and meaningful transitions. History is durable; automatic retention
pruning is not implemented.

Failure diagnostics retain only allowlisted codes and fixed safe messages:
ROUTER_UNAVAILABLE (legacy), ROUTER_UNREACHABLE, ROUTER_AUTH_FAILED,
ROUTER_TIMEOUT, ROUTER_PROTOCOL_ERROR, DISCOVERY_FAILED, RESULT_REJECTED.
Unknown codes become DISCOVERY_FAILED; exception text is never persisted.

## Outbound resilience and acceptance

Go uses context-bound 10-second requests, cancellable lease renewal, signal-based
shutdown, and jittered exponential outage backoff capped at 60 seconds. Successful
poll cycles reset backoff. Authentication rejection stops the process rather than
hammering Core. Lost lease communication cancels local execution without submitting
a stale result; Core recovery provides retry. No persistent offline queue exists.

Production requires HTTPS. Local HTTP requires both APP_ENV=local/testing and
COSMICLINK_AGENT_ALLOW_DEV_HTTP=1. Redirects are not followed. Optional local
settings: COSMICLINK_AGENT_POLL_INTERVAL (seconds, default 5),
NETWORK_AGENT_HEARTBEAT_SECONDS (default 30), COSMICLINK_AGENT_MAX_BACKOFF_SECONDS
(default 60). Never place real tokens in committed configuration.

Reusable acceptance: `python tests/Acceptance/agent_fleet.py` builds the actual
Agent into temporary storage, uses an isolated cosmiclink_test database and local
test-only HTTP proxy, and verifies disappearance, recovery, old-result rejection,
duplicate safety and secret absence. It requires migrated test schema, PHP, Go
and Python; it removes its tenant and stops owned processes. Tokens and captured
results stay in memory. `agent_regressions.py` runs live 6B and 6C against separate
fresh Fake Engine processes because their mutation-counter prerequisites differ.

## Security boundary

- The agent makes outbound HTTP/JSON requests to Laravel; **no public inbound agent port is required**.
- RouterOS API ports do not need public internet exposure. Production requires HTTPS/TLS from agent to Laravel.
- An enrollment token is returned only by server-side enrollment and is stored as a Laravel hash. It is never logged or stored in plaintext in the database.
- Agent jobs have no credential/payload column. On an authorized, atomic `DISCOVER_ROUTER` claim, Laravel decrypts the existing Router credential only in memory and returns it in that authenticated response. The Go agent never persists it or logs it.
- Jobs, agents, routers, and result submission are all checked against the authenticated agent tenant.

## Scope

Phase 6E supports **only** `DISCOVER_ROUTER`. The Go agent reuses the Phase 6D `DiscoveryProvider`; its RouterOS provider remains limited to `/system/resource/print`, `/system/identity/print`, `/ppp/profile/print`, `/ppp/secret/print`, `/ip/pool/print`, and `/queue/simple/print`.

There are zero RouterOS write commands, zero agent mutation job types, no arbitrary command/shell/script input, and no automatic `MANAGED` transition. Successful resources stay `DISCOVERED` until the existing explicit Laravel adoption workflow is used.

## Local agent

Use `network-engine/cmd/agent` with `COSMICLINK_CORE_URL`, `COSMICLINK_AGENT_TOKEN`, optional `COSMICLINK_AGENT_NAME`, and `NETWORK_DISCOVERY_PROVIDER=fake|routeros`. `fake` is the CI/demo/default simulation path. Do not commit a token. The process uses bounded HTTP timeouts, context cancellation, graceful shutdown, and secret-safe structured logs.