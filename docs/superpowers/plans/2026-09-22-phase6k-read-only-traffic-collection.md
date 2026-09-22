# Phase 6K Step 2 Read-Only Traffic Collection Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [x]`) syntax for tracking.

**Goal:** Add scheduled, read-only RouterOS traffic collection with restart-safe deltas, hotspot username aggregation, and PostgreSQL history.

**Architecture:** Extend the existing Go monitoring provider with a separate traffic collection contract and authenticated scheduled-only endpoint. Laravel owns cadence, validation, transactional persistence, delta calculation, five-minute aggregation, and retention; PostgreSQL is authoritative across collector restarts.

**Tech Stack:** Go 1.22, `go-routeros/v3`, Laravel/PHP, Eloquent, PostgreSQL, PHPUnit.

**Spec:** `C:\xampp\htdocs\cosmiclink\docs\superpowers\specs\2026-09-22-phase6k-read-only-traffic-collection-design.md`

## Global Constraints

- Reuse MikroTik → Go Network Engine → Laravel Core → PostgreSQL.
- RouterOS access is read-only and limited to six fixed `/print` commands.
- One connection and batch reads only; no per-customer or browser-triggered polling.
- PostgreSQL is authoritative for history and restart-safe counter state.
- Hotspot active sessions are the sole hotspot byte source; queue/interface domains stay separate.
- No UI, ranking, charts, billing, Copilot, RouterOS mutation, or new dependency.
- Preserve existing health endpoint and fake-driver behavior.
- Do not alter or delete the untracked benchmark source/executable.

---

### Task 1: Go normalized traffic contract and RouterOS provider

**Files:**
- Create: `C:\xampp\htdocs\cosmiclink\network-engine\internal\monitoring\traffic.go`
- Create: `C:\xampp\htdocs\cosmiclink\network-engine\internal\monitoring\traffic_routeros.go`
- Create: `C:\xampp\htdocs\cosmiclink\network-engine\internal\monitoring\traffic_routeros_test.go`
- Modify: `C:\xampp\htdocs\cosmiclink\network-engine\internal\monitoring\fake.go`

**Interfaces:**
- Produce `TrafficProvider.CollectTraffic(context.Context, RouterTarget, TrafficOptions) (TrafficSnapshot, error)`.
- Produce normalized `InterfaceTraffic`, `SimpleQueueTraffic`, `HotspotSessionTraffic`, `DHCPLease`, and `ARPEntry` values.
- `TrafficOptions.IncludeEnrichment` controls the two optional reads.

- [x] Write failing provider tests asserting exact required command order, optional enrichment commands, one connection/close, parsed counters, stable session keys, null malformed counters, secret exclusion, and zero mutations.
- [x] Run `go test ./internal/monitoring -run Traffic -count=1`; expect compile/test failure because traffic types/provider do not exist.
- [x] Add the traffic types with pointer counters so malformed/missing values remain unavailable rather than zero.
- [x] Implement `RouterOSMonitoringProvider.CollectTraffic` using one transport, per-read timeout, the fixed allowlist, and sequential reads.
- [x] Normalize `.id`, booleans, counter pairs, health context, hotspot identity, DHCP, and ARP fields; skip rows without required stable IDs.
- [x] Extend the fake monitoring provider with a deterministic traffic scenario and `CollectTraffic` without network access.
- [x] Run `gofmt` and `go test ./internal/monitoring -run Traffic -count=1`; expect PASS.

### Task 2: Go traffic API route and strict credential-safe request handling

**Files:**
- Modify: `C:\xampp\htdocs\cosmiclink\network-engine\internal\api\server.go`
- Create: `C:\xampp\htdocs\cosmiclink\network-engine\internal\api\traffic_monitoring_test.go`

**Interfaces:**
- Consume `monitoring.TrafficProvider` when the configured monitoring provider implements it.
- Produce authenticated `POST /api/v1/monitoring/traffic/collect` with the existing inline-or-reference credential request shape plus `include_enrichment`.

- [x] Write failing API tests for auth, strict JSON decoding, unavailable traffic provider, normalized success response, enrichment forwarding, and secret non-leakage.
- [x] Run `go test ./internal/api -run TrafficMonitoring -count=1`; expect failure because the route is absent.
- [x] Register the route and implement a handler that reuses bounded credential resolution, rejects invalid requests, requires `TrafficProvider`, and returns bounded failures.
- [x] Return the typed snapshot directly under `reachable: true`; never echo the request or credentials.
- [x] Run `gofmt` and `go test ./internal/api -run TrafficMonitoring -count=1`; expect PASS.

### Task 3: PostgreSQL traffic schema and Eloquent models

**Files:**
- Create: `C:\xampp\htdocs\cosmiclink\database\migrations\2026_09_22_000042_create_traffic_collection_tables.php`
- Create: `C:\xampp\htdocs\cosmiclink\app\Models\TrafficCollection.php`
- Create: `C:\xampp\htdocs\cosmiclink\app\Models\TrafficSample.php`
- Create: `C:\xampp\htdocs\cosmiclink\app\Models\TrafficBucket.php`
- Modify: `C:\xampp\htdocs\cosmiclink\app\Models\Router.php`
- Create: `C:\xampp\htdocs\cosmiclink\tests\Feature\Phase6KTrafficSchemaTest.php`

**Interfaces:**
- Produce tenant/router-scoped collection, sample, and five-minute bucket records with the uniqueness and indexes from the spec.

- [x] Write a failing schema/model test asserting columns, foreign keys/relationships, casts, fillable fields, unique constraints through duplicate inserts, and cascade deletion.
- [x] Run `php artisan test tests/Feature/Phase6KTrafficSchemaTest.php --no-coverage`; expect failure because tables/models do not exist.
- [x] Add one ordered migration creating all three tables with PostgreSQL-safe unsigned/big integer equivalents, JSON columns, timestamps, foreign keys, indexes, and unique constraints.
- [x] Add focused models and router relationships; do not add business logic to models.
- [x] Run the focused schema test; expect PASS.

### Task 4: Laravel traffic client contract

**Files:**
- Modify: `C:\xampp\htdocs\cosmiclink\app\Services\Network\GoNetworkMonitoringClient.php`
- Modify: `C:\xampp\htdocs\cosmiclink\tests\Unit\GoNetworkMonitoringClientTest.php`

**Interfaces:**
- Produce `collectTraffic(Router $router, bool $includeEnrichment): array`.
- Reuse the existing local observer reference or transient legacy credential payload and sanitizer.

- [x] Add failing tests for endpoint/payload selection, `include_enrichment`, local credential references, bounded connection failures, invalid responses, and recursive secret removal.
- [x] Run `php artisan test tests/Unit/GoNetworkMonitoringClientTest.php --no-coverage`; expect failures for the missing method.
- [x] Refactor only enough to share safe payload construction and HTTP response validation between health and traffic calls.
- [x] Implement `collectTraffic` against `/api/v1/monitoring/traffic/collect` and require boolean `reachable`.
- [x] Run the focused client test; expect PASS.

### Task 5: Transactional delta and bucket persistence

**Files:**
- Create: `C:\xampp\htdocs\cosmiclink\app\Services\Monitoring\TrafficCollectionService.php`
- Create: `C:\xampp\htdocs\cosmiclink\tests\Feature\Phase6KTrafficCollectionTest.php`

**Interfaces:**
- Produce `collectRouter(Router $router): ?TrafficCollection`, `runScheduled(): int`, and `prune(): array`.
- Consume `GoNetworkMonitoringClient::collectTraffic` and configuration under `monitoring.traffic`.

- [x] Write failing tests for first observation, increasing counters, reset, missing/reappearing source, malformed counters, duplicate collection, delayed collection, service restart, and tenant/router isolation.
- [x] Add failing aggregation tests proving exact session and username totals and no simple-queue/hotspot combination.
- [x] Run `php artisan test tests/Feature/Phase6KTrafficCollectionTest.php --no-coverage`; expect failure because the service is absent.
- [x] Implement eligibility and enrichment-due checks without network calls when traffic is disabled or the driver is not `engine`.
- [x] Validate/sanitize the typed response, reject partial required datasets, and parse `collected_at` with a bounded future tolerance.
- [x] In one transaction lock the exact router, reject duplicate/delayed collections before bucket writes, insert the collection, and compute each sample against PostgreSQL history.
- [x] Assign exact delta statuses from the spec and write null deltas whenever validity is uncertain.
- [x] Upsert five-minute source buckets only for valid deltas; additionally upsert one `hotspot_username` bucket per normalized username using the same valid session delta once.
- [x] Implement raw and bucket retention pruning.
- [x] Run the focused persistence test; expect PASS.

### Task 6: Scheduler and configuration integration

**Files:**
- Modify: `C:\xampp\htdocs\cosmiclink\config\monitoring.php`
- Modify: `C:\xampp\htdocs\cosmiclink\routes\console.php`
- Create: `C:\xampp\htdocs\cosmiclink\tests\Feature\Phase6KTrafficSchedulingTest.php`

**Interfaces:**
- Add `monitoring.traffic.enabled`, `enrichment_interval_seconds`, `raw_retention_days`, `bucket_retention_days`, and `future_tolerance_seconds`.
- Add scheduled commands `monitoring:collect-traffic` and `monitoring:prune-traffic`.

- [x] Write failing tests that fake the client and prove one collection per eligible router, no collection under fake/disabled config, enrichment cadence, and pruning command output.
- [x] Run `php artisan test tests/Feature/Phase6KTrafficSchedulingTest.php --no-coverage`; expect failure because commands/config do not exist.
- [x] Add fail-closed environment-backed configuration with traffic disabled by default.
- [x] Register the every-minute collection command with `withoutOverlapping` and daily prune command; do not add HTTP routes.
- [x] Run the focused scheduling test; expect PASS.

### Task 7: Automated verification and migration checks

**Files:**
- Review all files changed by Tasks 1–6.

- [x] Run `go test ./...` from `C:\xampp\htdocs\cosmiclink\network-engine`.
- [x] Run `go build ./...` from `C:\xampp\htdocs\cosmiclink\network-engine`.
- [x] Run `go vet ./...` from `C:\xampp\htdocs\cosmiclink\network-engine`.
- [x] Run all Phase 6K Laravel tests together.
- [x] Run the full feasible `php artisan test --no-coverage` suite.
- [x] Run migration rollback/re-run against `cosmiclink_test` only, guarded by the existing test database protection.
- [x] Run source searches for RouterOS write-shaped commands, secret fields, browser routes, and cross-domain byte aggregation.
- [x] Run `git diff --check` and inspect every changed file.

### Task 8: Short real read-only MikroTik smoke test

**Files:**
- Use existing local observer vault/configuration and the unmodified benchmark/engine tooling.
- Do not persist or print credentials.

- [x] Confirm a safe local observer reference, target host, RouterOS v6-compatible transport, and traffic provider are available without displaying secret values.
- [x] Run one traffic collection with enrichment disabled and capture only bounded counts/identity/counter-presence evidence.
- [x] Run one collection with enrichment enabled if DHCP/ARP permissions are present; optional enrichment denial must not trigger a write or expose raw errors.
- [x] Check the provider/transport mutation count remains zero and that only allowlisted reads were attempted.
- [x] If credentials, target, or safe verification evidence are unavailable, stop and report the concrete safety blocker rather than substituting a write-capable or browser path.

### Task 9: Final review and focused commit

**Files:**
- Include only approved Phase 6K source, tests, migrations, spec, and plan.
- Exclude `C:\xampp\htdocs\cosmiclink\network-engine\monitoring-benchmark.exe` and unrelated artifacts.

- [x] Re-run `git status`, `git diff --stat`, `git diff --check`, and inspect staged paths.
- [x] Confirm no secrets, raw credentials, UI, billing, ranking, chart, Copilot, mutation, or unrelated files are staged.
- [x] Run the final narrow regression tests if any review edit was needed.
- [x] Create exactly one commit: `Phase 6K add read-only traffic collection`.
- [x] Verify the commit exists locally and the working tree contains only pre-existing/unrelated untracked artifacts; do not push.