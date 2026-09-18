# CosmicLink Phase 6G — Real Network Monitoring (Read-Only) — Implementation Plan

Status: PLAN ONLY (no implementation)
Prepared against verified checkpoint: `422f0e2` — *CosmicLink Phase 6F verified Agent observability and fleet management*
Worktree: `C:\xampp\htdocs\cosmiclink-phase6f`
Branch: Phase 6G should branch `phase-6g-real-monitoring` from `422f0e2`.

---

## 1. Current architecture audit (evidence)

Verified by direct inspection of the working tree:

**Monitoring core (Phase 4A)**
- `app/Services/Monitoring/Contracts/MonitoringDriver.php` — single-method contract: `check(array $config): array` of `HealthObservationResult`.
- `app/Services/Monitoring/FakeMonitoringDriver.php` — simulation driver returning pseudo results.
- `app/Services/Monitoring/MonitoringService.php` — resolves driver from `config/monitoring.php` (`.env`-driven driver/interval/resources), executes `check()`, persists each result into `health_observations`, then delegates to `OutageCorrelationService`.
- `app/Services/Monitoring/HealthObservationResult.php` — value object: `resourceType`, `resourceId`, `status` (`online|offline|degraded|unknown`), `message`, `metadata`.
- `database/migrations/2026_09_17_000019_create_health_observations_table.php` — tenant-scoped: `resource_type`, `resource_id`, `status`, `message`, `metadata` (JSON), `observed_at`; indexed on (tenant, resource, observed_at).
- `resources/js/modules/monitoring/MonitoringPage.vue` — frozen CosmicLabs design system; reads latest observations + fleet state.

**Outage intelligence (Phase 4B/4C)**
- `app/Services/Monitoring/OutageCorrelationService.php` — consumes **persisted** `HealthObservation` rows; correlates OFFLINE observations inside a configurable window (`config/outages.php`), groups by scope (e.g. router), creates ONE `OutageIncident` per correlated group; auto-resolves on subsequent ONLINE observations; tenant-scoped. Phase 4C notification automation hooks incident open/resolve.
- `app/Models/OutageIncident.php` — tenant-scoped incident with lifecycle timestamps.

**Network/Go stack (Phase 6B–6F)**
- `network-engine/internal/api/server.go` — Go Engine HTTP API (read-only discovery endpoint used by `GoNetworkDiscoveryClient`).
- `network-engine/internal/provider/routeros_discovery.go` — **FROZEN** Phase 6D provider. Allowlist exactly: `/system/resource/print`, `/system/identity/print`, `/ppp/profile/print`, `/ppp/secret/print`, `/ip/pool/print`, `/queue/simple/print`. Verified against real hEX, RouterOS 6.49.13, user `cosmiclink` (`read,api` only).
- `network-engine/internal/agent/agent.go` — outbound-only Agent (heartbeat → claim → renew → execute → submit), lease-aware, bounded backoff, graceful shutdown, no inbound listener.
- `app/Services/Network/NetworkAgentService.php` — Phase 6F: indexed `token_id.secret` auth (+ legacy path), heartbeat sanitization, capability-gated claim, 120s leases, attempt fencing, idempotent recovery.
- `routes/console.php` — scheduler already runs `monitoring:run` and `network-agents:recover-jobs`.

## 2. Existing Phase 4A monitoring flow

`monitoring:run` (scheduler, cadence from config) → `MonitoringService::runFromConfig()` → `MonitoringDriver->check(config)` → N× `HealthObservationResult` → persisted to `health_observations` → `OutageCorrelationService::correlate()` → incidents + notifications. Driver selection is entirely config-driven; **today only `fake` exists**.

## 3. Existing Outage Intelligence flow

Real persisted observations only. OFFLINE observations within the correlation window for the same scope produce a single incident; ONLINE resolves it; incident creation is idempotent per open window. The design is sound for real data **provided** we guarantee (a) UNKNOWN observations never open incidents, and (b) monitoring-source unavailability is never written as customer OFFLINE (§11, §14).

## 4. Existing Agent/Go architecture

Two verified transport paths:
1. **Direct path (6B/6C/6D):** Laravel → HTTP → Go Engine → `RouterOSDiscoveryProvider` → RouterOS API. Works when Laravel can reach the Engine (same LAN — true today).
2. **Agent path (6E/6F):** Agent → outbound HTTPS → Laravel; Agent executes network-local work. Mandatory for ISP LANs where RouterOS must not be exposed and Core cannot reach inside.

## 5. Identified integration gaps

- No real `MonitoringDriver` exists (only fake).
- Go Engine has a discovery provider but **no monitoring provider**; discovery reads exclude `/ppp/active/print` (current sessions).
- No mapping from RouterOS PPPoE sessions to `CustomerConnection` (Phase 6H owns reconciliation/adoption; 6G must not adopt).
- No freshness/expiry semantics: stale observations are indistinguishable from fresh ones at correlation time.
- The Agent job lifecycle (`DISCOVER_ROUTER`) is unsuitable for continuous monitoring (§7).
- No retention/pruning for `health_observations`.

## 6. Proposed Phase 6G architecture

```
MikroTik hEX (RouterOS 6.49.13, user cosmiclink: read,api)
        │  READ ONLY (monitoring allowlist, separate provider file)
        ▼
RouterOSMonitoringProvider (NEW: network-engine/internal/provider/routeros_monitoring.go)
        │  shares the narrow read-only transport with discovery; routeros_discovery.go untouched
        ▼
Go Engine HTTP API /api/v1/monitor/router (NEW, read-only)
        │
        ▼
GoMonitoringDriver (NEW: app/Services/Monitoring/GoMonitoringDriver.php)
        │  implements the existing MonitoringDriver contract — no second monitoring system
        ▼
MonitoringService (UNCHANGED orchestration)
        ├── RouterObservationMapper   → router HealthObservation (ONLINE/OFFLINE/UNKNOWN + CPU/mem/uptime metadata)
        ├── CustomerObservationMapper → per-CustomerConnection HealthObservation via PPPoE active-session match
        ▼
health_observations → OutageCorrelationService (UNCHANGED) → incidents/notifications
        └── freshness gate (NEW): observations older than the freshness window are treated as UNKNOWN
```

## 7. Monitoring execution model decision

| Option | Verdict | Why |
|---|---|---|
| A. `MONITOR_ROUTER` job via 6F Agent job lifecycle | Rejected for 6G | Continuous sampling (~1,440–2,880/router/day) would churn jobs/attempts/events, abuse lease/recovery semantics designed for finite auditable work, and multiply DB growth. |
| B. Core polls a dedicated Agent endpoint (inbound) | Rejected | Violates outbound-first; Core cannot reach remote Agents. |
| C. Agent periodically submits observations (outbound) | Correct end-state for remote ISP LANs; needs Agent cadence, submission auth, backpressure, freshness handling. | Deferred: contract designed (§8), not implemented in 6G. |
| D. **Hybrid (CHOSEN):** `GoMonitoringDriver` → Go Engine → `RouterOSMonitoringProvider` | Chosen | Reuses the verified 6B/6C/6D direct path; works today on the owner's own network; zero changes to frozen discovery code; smallest new surface; real-hardware acceptance immediate. |

**Decision: Option D for Phase 6G.** Laravel scheduler still drives cadence via `MonitoringService` (Laravel owns intent + persistence; Go owns network-local execution). The Agent-submission path (§8) can later be added as an additional driver feeding the same pipeline.


## 8. Router monitoring contract

`POST /api/v1/monitor/router` (Engine-side, read-only, guarded by the existing Engine service token used by `GoNetworkDiscoveryClient`).

Request (sanitized): `{ router: { host, port, username, password: "in-memory-only" } }` — password travels in the request body over HTTPS/LAN, never logged, never persisted.

Normalized response:
```json
{
  "reachable": true,
  "identity": "router-name",
  "version": "6.49.13",
  "board": "hEX",
  "uptime": "12d3h4m",
  "cpu_load_percent": 14,
  "memory_used_percent": 42,
  "ppp_active": [
    { "name": "customer-01", "service": "pppoe", "address": "...", "uptime": "...", "caller_id": "..." }
  ],
  "failure": null
}
```
Failure envelope: `{ "reachable": false, "failure": { "code": "ROUTER_UNREACHABLE|ROUTER_AUTH_FAILED|ROUTER_TIMEOUT|ROUTER_PROTOCOL_ERROR|MONITORING_TIMEOUT", "message": "short safe message" } }`. No raw RouterOS payloads, no secrets. PPPoE entries contain **no passwords** (`/ppp/active/print` returns no secret field — verify on hardware).

## 9. PPPoE monitoring contract

Read command: `/ppp/active/print` (RouterOS v6 read-only; API user already has `read,api`). Normalized per-session: `name`, `service` (filter `pppoe`), `address`, `uptime`, `caller_id`. Matching rule: a `CustomerConnection` whose stored PPP account equals session `name` is ONLINE this cycle; listed-but-unmapped sessions are recorded as router-level metadata only (no automatic adoption); mapped connections not in the active set are OFFLINE. Session count and unmapped names go into router observation metadata (bounded list, max ~100 names).

## 10. Customer health mapping rules

- Only connections with an existing explicit account mapping are monitored per-customer. **No silent adoption** — unmapped sessions remain router-level metadata until Phase 6H reconciliation.
- Mapping does not change `DISCOVERED`/`MANAGED` resource states. 0 adoption transitions.
- If no mapping column exists yet, Phase 6G adds a nullable PPP-account column on `customer_connections` **filled only by operators**, not by discovery.

## 11. Agent-vs-router-vs-customer health semantics (mandatory)

Three independent dimensions, never collapsed:
- **AGENT health** (6F): ONLINE/STALE/OFFLINE from heartbeat recency. Relevant only in the Agent path; direct Engine path uses Engine reachability instead.
- **ROUTER health**: ONLINE (API reachable, resource read OK) / OFFLINE (explicit connection failure) / UNKNOWN.
- **CUSTOMER health**: ONLINE/OFFLINE (mapped-session truth this cycle) / DEGRADED (reserved) / UNKNOWN.

Freshness gate: if the monitoring source (Engine or Agent) could not produce a valid router read, **no customer OFFLINE rows are written**; connections keep their last state and the router observation is UNKNOWN with `OBSERVATION_STALE`/`ENGINE_UNAVAILABLE` code. Correlation treats UNKNOWN as non-incident. Agent OFFLINE ⇒ customer UNKNOWN after freshness expiry, never customer OFFLINE. Router reboot ≠ customer outage beyond actual session drops; PPPoE reconnection inside the correlation window is absorbed by existing incident open/resolve logic (verify in tests).


## 12. RouterOS read-only monitoring allowlist (proposed)

Exactly these commands, in `routeros_monitoring.go` only:
1. `/ppp/active/print` — active sessions (new)
2. `/system/resource/print` — CPU, memory, uptime (re-read here via shared transport; discovery file not expanded)
3. `/system/identity/print` — router identity (as above)

No other commands. No write-shaped commands. No generic execution. Allowlist is a Go slice enforced by the provider before transport dispatch; unsupported command ⇒ local `ROUTER_PROTOCOL_ERROR` refusal.

## 13. Scheduling / cadence design

Laravel scheduler remains the single cadence owner (extends `monitoring:run`, no new infra):
- Router health + PPPoE state: every 60 s (default `MONITORING_INTERVAL=60`; owner-network MVP).
- Resource telemetry (CPU/mem): attached to the same cycle — no separate job.
- `monitoring:prune-observations` (NEW): daily retention pruning (default 14 days, configurable).
No Redis/queue dependency for MVP; correlation stays in the synchronous run path as today.

## 14. Failure classification

Bounded codes persisted only in observation `metadata.failure_code`:
`ROUTER_UNREACHABLE`, `ROUTER_AUTH_FAILED`, `ROUTER_TIMEOUT`, `ROUTER_PROTOCOL_ERROR`, `MONITORING_TIMEOUT`, `ENGINE_UNAVAILABLE`, `OBSERVATION_STALE`, `AGENT_UNAVAILABLE` (future path).
Rules: transport/auth failures ⇒ router OFFLINE + customers UNKNOWN (no customer OFFLINE written); a single failed cycle must not open a false incident (correlation window absorbs single blips); UNKNOWN never opens incidents.

## 15. Observation persistence strategy

`health_observations` (Phase 4A) remains the single store:
- Router observation: `resource_type=router`, status + metadata (cpu, memory, uptime, version, ppp_active_count, failure_code).
- Customer observations: `resource_type=customer_connection`, one row per mapped connection per cycle.
**Write strategy: state-change + periodic checkpoint hybrid** — write a row only when status changed since the last persisted row for that resource, plus a forced checkpoint at least every 5 minutes, so correlation windows and "last ONLINE" queries stay correct while eliminating steady-state duplication. `monitoring:prune-observations` caps growth.

## 16. DB write-volume estimates

Full-write (naive) at 60 s cadence:
| Connections | Rows/day | 14-day retention |
|---|---|---|
| 100 | 144,000 | ~2.0 M |
| 500 | 720,000 | ~10.1 M |
| 1,000 | 1,440,000 | ~20.2 M |

Hybrid (typical stable network ~98% stable): ≈12 checkpoints/h/connection + change events ⇒ 100 conn ≈ 29 k rows/day (−80%); 1,000 conn ≈ 290 k rows/day. PostgreSQL handles this trivially; retention keeps storage bounded (~50–500 MB over 14 days at 1,000 connections). Justifies the hybrid without a time-series platform.

## 17. Redis / queue role

None required in 6G. PostgreSQL is authoritative; `MonitoringService` writes synchronously as today. Redis/queue remain available for later Agent-path backpressure (Option C) — explicitly out of scope.

## 18. Tenant isolation

All new paths flow through `MonitoringService`, already tenant-scoped (`health_observations.tenant_id` + existing indexes). The Engine is tenant-agnostic exactly as in 6C discovery: it executes one router operation with tenant-resolved credentials supplied per request. No cross-tenant aggregation introduced; fleet/UI queries stay tenant-scoped.

## 19. Security / secrets model

- Router password: decrypted in memory by Laravel (existing `Router` encryption), sent in the Engine request body (HTTPS/LAN), never logged/persisted in observations or stored by the Engine.
- PPPoE passwords: `/ppp/active/print` exposes none; normalization defensively strips secret-shaped fields.
- Engine service token: existing mechanism only; never in observation metadata or logs.
- Agent tokens: unaffected (Agent path not implemented in 6G).
- Tests assert absence of `password|secret|token|authorization` keys in every persisted row, payload, and log line.

## 20. UI changes

Extend frozen modules only. `MonitoringPage.vue`: REAL/SIMULATION source badge (from driver config), router card (CPU%, mem%, uptime, RouterOS version, active PPPoE count), per-customer state list with existing ONLINE/OFFLINE/DEGRADED/UNKNOWN chips. Cosmic Terminal Language: `[ROUTER] ONLINE`, `[PPPOE] 27/30 ONLINE`, `[WARN] OBSERVATION_STALE`. No historical graphs until real data justifies them; no fake charts; no global redesign.

## 21. Laravel implementation tasks

1. `GoMonitoringDriver` in `app/Services/Monitoring/` implementing the existing `MonitoringDriver` contract (same result shape as `FakeMonitoringDriver`), delegating to a new `GoNetworkMonitoringClient` modeled on `GoNetworkDiscoveryClient` (base URL, service token, bounded timeout, safe error mapping to Phase 6D error codes).
2. Driver selection: extend `config/monitoring.php` (`driver => fake|engine`); `AppServiceProvider` binds config-driven, default `fake` — existing tests/behavior unchanged.
3. `MonitoringService` cycle: unchanged persistence semantics — same `HealthObservation` writes, same freshness/UNKNOWN rules, plus retention/pruning scheduler per §15.
4. Controllers/API: add source metadata (driver name, last cycle time, freshness flags) to existing endpoints only; no new business endpoints in 6G.
5. Optional tenant-scoped `router_telemetry` handling only if §15 option A chosen (model + repo + one index).
6. No Agent enrollment, no job-type additions, no new Agent routes — 6E/6F Agent code untouched.

## 22. Go implementation tasks

1. New `internal/monitoring` package: `Provider` interface, `RouterOSMonitoringProvider` (narrow read-only transport over the existing `routeros` client package), `FakeMonitoringProvider` (deterministic fixture matching Phase 4A fake volumes).
2. One new API route in `internal/api/server.go`: `POST /api/v1/monitoring/collect` (service-token auth, same middleware as discovery/mutations). Read-only.
3. No changes to `routeros_discovery.go`, Agent package, discovery provider interfaces, mutation handlers, Phase 6B handlers.
4. Unit tests: normalization, allowlist enforcement, timeout, auth rejection, fixture stability.

## 23. Migration tasks

- Preferred: **none**. `health_observations` satisfies 6G.
- Conditional (option A only): one migration adding `router_telemetry` + index; reversible `down()`.
- Retention = scheduled delete, not schema change.

## 24. Focused test plan (Laravel)

1. `GoMonitoringDriver` maps Engine responses to `HealthObservationResult` (online/offline/degraded/unknown).
2. Engine timeout/unreachable/protocol error → `unknown`, never `offline` (no false outages).
3. Agent-freshness rule: stale Engine data → `unknown` + `OBSERVATION_STALE`.
4. Phase 4A regression: default config resolves `FakeMonitoringDriver`; identical behavior.
5. Correlation unchanged: single-incident grouping fed by driver-supplied real-shaped observations (stub driver, no hardware).
6. Secret non-leakage assertions on persisted rows/logs.
7. Pruning scheduler deletes rows older than N days; keep-latest-per-window respected.

## 25. Fake-provider E2E plan

1. Fixture engine → real Laravel cycle → observations persisted → MonitoringPage REAL badge + states.
2. Failure injected (router unreachable) → `unknown` persisted → no incident opened (failure ≠ state).
3. Multi-customer offline on same router → ONE correlated incident (4B regression with real-shaped data).
4. Duplicate-cycle idempotency: unchanged state between cycles duplicates no business artifacts.

## 26. Real MikroTik hardware acceptance plan

Prerequisites: verified hEX (RouterOS 6.49.13), `cosmiclink` read-only user.
1. Baseline router fingerprint: `/system/resource/print`, `/system/identity/print`, normalized config export (mask identity/addresses).
2. Start Go Engine with `MONITORING_PROVIDER=routeros`; run collect cycle.
3. Assert: router ONLINE, resources present, active-PPP list matches manual read-only `/print` output.
4. Re-run fingerprint → identical (minus dynamic counters) → proves **0 writes**.
5. Router-side check as `cosmiclink`: history contains only the 3 allowlisted read commands.
6. Negative test: wrong credential → `ROUTER_AUTH_FAILED` → `unknown`, no customer outage.
7. Secrets only via engine env; never printed/persisted.

## 27. Regression plan (Phase 4A/B/C, 6B–6F)

- Full `php artisan test` must pass unchanged (default driver = fake → 4A/4B/4C identical).
- 6B–6F: Go `go test ./...` + `go vet ./...`; Agent/lease/fencing suites untouched and green.
- `routeros_discovery.go` diff vs `422f0e2` must be empty → no discovery hardware re-verification required.
- Live-only Engine tests re-run as before against fresh fake Engine processes.

## 28. Zero-mutation acceptance

- Go source scan: monitoring package contains no write-capable commands.
- Router fingerprint before/after identical (hardware acceptance).
- Incidents change only via existing correlation service with genuine offline observations.
- Agent mutation job types = 0 (no new job types exist).
- PPPoE enable/disable/disconnect, profile change, firewall change counters = 0.
- MANAGED transitions = 0.

## 29. Rollback strategy

- Config-only: `monitoring.driver = fake` → instant reversion to Phase 4A behavior; no schema/data loss.
- Option-A table (if used): clean `down()`; nothing else touched.
- No Agent/core state mutated → 6E/6F artifacts never require rollback.

## 30. Expected file changes / creations

Laravel:
- create `app/Services/Monitoring/GoMonitoringDriver.php`
- create `app/Services/Network/GoNetworkMonitoringClient.php`
- modify `config/monitoring.php` (driver + retention + freshness options)
- modify `app/Providers/AppServiceProvider.php` (driver binding)
- modify `routes/console.php` or a console command (pruning schedule)
- modify `resources/js/modules/monitoring/MonitoringPage.vue` (source badge + router card)
- create `tests/Feature/Phase6G*Test.php`
- optional: `database/migrations/2026_XX_create_router_telemetry.php` (option A only)

Go (network-engine):
- create `internal/monitoring/provider.go`, `internal/monitoring/routeros.go`, `internal/monitoring/fake.go`
- modify `internal/api/server.go` (one new read-only route)
- untouched: `internal/provider/routeros_discovery.go`, `internal/agent/*`, mutation handlers

Docs:
- create/extend `docs/NETWORK_MONITORING.md`

## 31. Risks

- RouterOS 6.49 field-name variance in `/ppp/active/print` → explicit field whitelist + fixtures validated against 6.49.13.
- hEX CPU load under polling → 60s cadence; resource reads at 300s.
- Transient API errors becoming false outages → error-vs-state separation (§14) before correlation.
- Observation growth → retention + optional checkpoint strategy (§15/16).
- Scope creep (SNMP/multi-agent) → explicit non-goals (§32).

## 32. Non-goals (6G)

RouterOS writes; Smart Auto-Isolation; PPPoE provisioning; MANAGED mode; automatic adoption; SNMP; OLT/AP/CPE monitoring; NetFlow; traffic accounting/billing; shaping; remote shell; arbitrary commands; Agent-based monitoring transport; Agent installer; auto-update; SaaS enrollment; multi-Agent HA; PostGIS map; historical charts beyond persisted observations; SLA reporting.

## 33. Proposed final verification gates

1. `gofmt` clean on changed Go files.
2. `go test ./...` + `go vet ./...` exit 0.
3. `php artisan test` full suite exit 0 (report counts).
4. `vendor/bin/pint --test` pass.
5. `npm run build` success.
6. `git diff --check` clean.
7. Phase 4A/4B/4C + 6B + 6C + 6D + 6E + 6F regressions explicitly green.
8. Fake E2E §25 all assertions pass.
9. Hardware acceptance §26: 0 writes, identical fingerprint.
10. Secret-leak audit across DB/logs/API/telemetry passes.
11. Zero-mutation acceptance §28 passes.
12. `routeros_discovery.go` byte-identical to `422f0e2`.

All gates pass → `PHASE 6G — FULLY VERIFIED`; otherwise `PHASE 6G — PARTIAL / NOT VERIFIED`.

---

## Decision summary

- **Execution model:** Option C for 6G — Laravel→Engine push-cycle; Agent monitoring deferred to a later phase as a dedicated lease-aware `MONITOR_ROUTER` capability (Option A semantics sketched in §7 so the later phase is incremental, not a rewrite).
- **Router reads:** 3 allowlisted read commands only.
- **Latency:** excluded from 6G; revisit once Agent transport exists.
- **Persistence:** `health_observations` + scheduled retention; `router_telemetry` only if profiling justifies it.
- **Health isolation:** Agent health, router health, and customer health stay three distinct dimensions; Agent/core/API failures always degrade to `unknown`, never customer `offline`.

