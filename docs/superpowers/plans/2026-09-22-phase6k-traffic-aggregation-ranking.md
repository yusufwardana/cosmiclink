# Phase 6K Step 3 Traffic Aggregation and Ranking Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn verified five-minute PostgreSQL traffic buckets into tenant-scoped traffic overview, subscriber ranking, history, peak-hour, customer-enrichment, and interface-intelligence APIs.

**Architecture:** A Laravel-only `TrafficAnalyticsService` queries `traffic_buckets`; no analytics request depends on monitoring services, the Go engine, or RouterOS. Period parsing and connection-mode classification are explicit value objects, API validation is isolated in a Form Request, and the controller only derives tenant context and serializes service results. A minimal additive bucket migration retains safe source metadata and marks authoritative subscriber buckets so static queues can be separated from dynamic Hotspot queues without more polling.

**Tech Stack:** Laravel 12, PHP 8.3, Eloquent/query builder, PostgreSQL, PHPUnit.

**Spec:** User-approved Phase 6K Step 3 requirements dated September 22, 2026, building on `docs/superpowers/specs/2026-09-22-phase6k-read-only-traffic-collection-design.md` and commit `74a509aeed134f7dabd547f520b6b21b8cd63144`.

## Global Constraints

- Normal analytics queries use existing five-minute buckets, not raw 15-second samples.
- Supported periods are exactly `today`, `7d`, and `30d`, using half-open UTC ranges `[start, end)`.
- Subscriber traffic uses actual byte deltas only; configured queue `max-limit` is never used.
- `STATIC_SIMPLE_QUEUE` includes only authoritative non-dynamic Simple Queue buckets.
- `HOTSPOT` uses only `hotspot_username` buckets, already aggregated across sessions by normalized username.
- Dynamic Simple Queues are never added to Hotspot totals.
- `PPPOE` remains an explicit unsupported mode until authoritative PPPoE buckets exist.
- Interface/segment totals stay separate from subscriber totals.
- Unmapped identities remain visible; mapping never guesses from package limits or unrelated interface totals.
- Browser APIs read PostgreSQL only and never contact MikroTik or the Go engine.
- No Vue UI, charts, billing, auto-isolation, Copilot, mutation, routing changes, polling changes, or new infrastructure.
- Do not push.

---

### Task 1: Make bucket semantics sufficient for safe analytics

**Files:**
- Create: `database/migrations/2026_09_22_000043_add_analytics_context_to_traffic_buckets.php`
- Modify: `app/Models/TrafficBucket.php`
- Modify: `app/Services/Monitoring/TrafficCollectionService.php`
- Modify: `tests/Feature/Phase6KTrafficCollectionTest.php`
- Create: `tests/Feature/Phase6KTrafficAnalyticsSchemaTest.php`

**Interfaces:**
- `traffic_buckets.subscriber_authoritative` is true only for non-dynamic `simple_queue` and derived `hotspot_username` buckets.
- `traffic_buckets.metadata` retains only sanitized source identity context such as interface name/type and queue name/target/dynamic.
- Existing ambiguous rows remain false and therefore fail closed from subscriber ranking.

- [ ] Write failing schema and collection tests asserting the new columns/index, interface metadata persistence, static queue authority, dynamic queue exclusion, and Hotspot username authority.
- [ ] Run `php artisan test tests/Feature/Phase6KTrafficAnalyticsSchemaTest.php tests/Feature/Phase6KTrafficCollectionTest.php --no-coverage`; expect failure before the migration/model/service changes.
- [ ] Add the migration with nullable JSON metadata, boolean `subscriber_authoritative` default false, and tenant/router/source/authority/time index.
- [ ] Extend `TrafficBucket` fillable/casts.
- [ ] Pass sanitized sample metadata and an authority flag into bucket upserts; do not create a subscriber bucket for dynamic Simple Queue samples, and mark derived Hotspot username buckets authoritative.
- [ ] Run the two focused tests; expect PASS.

### Task 2: Define exact periods and analytics contracts

**Files:**
- Create: `app/Services/Monitoring/TrafficAnalyticsPeriod.php`
- Create: `app/Services/Monitoring/TrafficConnectionMode.php`
- Create: `tests/Unit/TrafficAnalyticsPeriodTest.php`

**Interfaces:**
- `TrafficAnalyticsPeriod::from(string $period, CarbonInterface $now): self` accepts only `today`, `7d`, `30d` and exposes UTC `start`, `end`, `key`, and `seconds`.
- `TrafficConnectionMode` constants are `STATIC_SIMPLE_QUEUE`, `HOTSPOT`, and `PPPOE`; `bucketSource()` returns `simple_queue`, `hotspot_username`, or null.

- [ ] Write failing unit tests for exact UTC today, rolling seven-day, rolling thirty-day half-open boundaries, invalid periods, and PPPoE’s unsupported source.
- [ ] Run `php artisan test tests/Unit/TrafficAnalyticsPeriodTest.php --no-coverage`; expect class-not-found failure.
- [ ] Implement immutable period and mode classes without framework/network dependencies.
- [ ] Run the focused unit test; expect PASS.

### Task 3: Implement bucket-based aggregation and ranking queries

**Files:**
- Create: `app/Services/Monitoring/TrafficAnalyticsService.php`
- Create: `tests/Feature/Phase6KTrafficAnalyticsTest.php`

**Interfaces:**
- `overview(int $tenantId, TrafficAnalyticsPeriod $period, array $filters = []): array`
- `rankSubscribers(int $tenantId, TrafficAnalyticsPeriod $period, string $mode, string $metric, int $limit, array $filters = []): array`
- `subscriberHistory(int $tenantId, TrafficAnalyticsPeriod $period, string $mode, string $identity, array $filters = []): array`
- `interfaceHistory(int $tenantId, TrafficAnalyticsPeriod $period, array $filters = []): array`
- `peakHours(int $tenantId, TrafficAnalyticsPeriod $period, array $filters = []): array`

- [ ] Write failing synthetic-data tests for today/7d/30d totals, upload/download/total, active-bucket average throughput, peak five-minute throughput, peak hour, exact period boundaries, router filtering, tenant isolation, and duplicate-safe unique bucket behavior.
- [ ] Add failing ranking tests for total/download/upload/lowest ordering and limits 10/20.
- [ ] Add failing mode tests proving static-only Simple Queue ranking, multi-session Hotspot username aggregation, no dynamic queue/Hotspot double-counting, and explicit unsupported PPPoE behavior.
- [ ] Run `php artisan test tests/Feature/Phase6KTrafficAnalyticsTest.php --no-coverage`; expect service-not-found failure.
- [ ] Implement tenant-first bucket query builders with `>= start` and `< end`, optional router filter, PostgreSQL aggregate expressions, deterministic identity tie-breaking, and no raw-sample query.
- [ ] Calculate average throughput as `total_bytes * 8 / (distinct_active_bucket_count * 300)` and peak throughput as the maximum five-minute bucket’s `total_bytes * 8 / 300`; return integer bits per second.
- [ ] Calculate peak hour by grouping UTC buckets to the hour and choosing highest total bytes with earliest-hour tie-breaking.
- [ ] Keep subscriber mode totals separate in overview and interface totals separate from both; do not synthesize a combined cross-mode subscriber total.
- [ ] Run the focused analytics test; expect PASS.

### Task 4: Enrich mapped customers without hiding network identities

**Files:**
- Modify: `app/Services/Monitoring/TrafficAnalyticsService.php`
- Modify: `tests/Feature/Phase6KTrafficAnalyticsTest.php`

**Interfaces:**
- Exact normalized identity mapping is tenant/router scoped: Hotspot username or static queue subject key equals `NetworkAccount.username`.
- One eager query loads matching `NetworkAccount.connections.customer` and `connections.internetPackage`; no per-ranking-row query is allowed.
- Each result contains `mapped`, `customer`, `connection`, and `package`, with nulls for unmapped identities.

- [ ] Add failing tests for mapped connection enrichment, unmapped identity visibility, same username on another router/tenant not mapping, and customer/connection/package filters.
- [ ] Add a query-count assertion proving enrichment does not grow with ranking row count.
- [ ] Run the focused analytics test; expect the mapping assertions to fail.
- [ ] Implement batched exact identity lookup and deterministic connection selection scoped to tenant and router.
- [ ] Apply customer/connection/package filters by resolving allowed mapped identities before the aggregate query; unmapped identities remain included when no mapping filter is requested.
- [ ] Run the focused analytics test; expect PASS.

### Task 5: Expose thin tenant-scoped PostgreSQL-only APIs

**Files:**
- Create: `app/Http/Requests/TrafficAnalyticsRequest.php`
- Create: `app/Http/Controllers/Api/V1/TrafficAnalyticsController.php`
- Modify: `routes/api.php`
- Create: `tests/Feature/Phase6KTrafficAnalyticsApiTest.php`

**Interfaces:**
- `GET /api/v1/traffic/overview`
- `GET /api/v1/traffic/rankings`
- `GET /api/v1/traffic/subscriber-history`
- `GET /api/v1/traffic/interface-history`
- `GET /api/v1/traffic/peak-hours`
- Shared filters: `period`, `router_id`, `customer_id`, `connection_id`, `package_id`; rankings also accept `mode`, `metric`, `top`; subscriber history requires `mode`, `identity`.

- [ ] Write failing API tests for auth, validation, tenant derivation, all five response shapes, filters, foreign-tenant router rejection, and PPPoE unsupported response.
- [ ] Bind mocks for `GoNetworkMonitoringClient` and `TrafficCollectionService` that throw on use, proving every endpoint remains PostgreSQL-only.
- [ ] Run `php artisan test tests/Feature/Phase6KTrafficAnalyticsApiTest.php --no-coverage`; expect route/controller failure.
- [ ] Implement request rules with exact enums, top values 10/20, tenant-owned foreign-key existence checks, and safe identity length bounds.
- [ ] Implement a thin controller using `Auth::user()->tenant_id`, the period value object, and `TrafficAnalyticsService`; return JSON under `data`.
- [ ] Register only authenticated GET routes; add no browser-triggered collection endpoint.
- [ ] Run the focused API test; expect PASS.

### Task 6: Query-plan indexes and performance regression checks

**Files:**
- Modify: `database/migrations/2026_09_22_000043_add_analytics_context_to_traffic_buckets.php`
- Modify: `tests/Feature/Phase6KTrafficAnalyticsSchemaTest.php`
- Modify: `tests/Feature/Phase6KTrafficAnalyticsTest.php`

**Interfaces:**
- Indexes cover tenant/router/source/authority/time and tenant/source/subject/time access paths.

- [ ] Add failing schema assertions for both analytics indexes.
- [ ] Add query-count tests for overview/ranking with 20 identities and customer enrichment.
- [ ] Run the schema and analytics tests; expect failures until indexes/query batching are exact.
- [ ] Finalize indexes and eliminate any N+1 query path without caching or new infrastructure.
- [ ] Run focused Phase 6K tests; expect PASS.

### Task 7: Full verification, scope audit, and focused commit

**Files:**
- Review every changed file; no Go or RouterOS source should change.

- [ ] Run all Phase 6K tests: `php artisan test tests/Unit/TrafficAnalyticsPeriodTest.php tests/Feature/Phase6KTrafficAnalyticsSchemaTest.php tests/Feature/Phase6KTrafficAnalyticsTest.php tests/Feature/Phase6KTrafficAnalyticsApiTest.php tests/Feature/Phase6KTrafficCollectionTest.php tests/Feature/Phase6KTrafficSchedulingTest.php tests/Feature/Phase6KTrafficSchemaTest.php --no-coverage`.
- [ ] Run the full Laravel suite: `php artisan test --no-coverage`.
- [ ] Run Pint on every changed PHP file.
- [ ] Run `git diff --check` and guarded migration rollback/re-run against `cosmiclink_test` only.
- [ ] Search changed application/API code for `GoNetworkMonitoringClient`, `TrafficCollectionService`, HTTP clients, RouterOS commands, billing, mutation, and UI changes; analytics production code must contain none of those dependencies except the deliberate Step 2 bucket-context update in `TrafficCollectionService`.
- [ ] Inspect SQL/query builder use to confirm dashboard paths query `traffic_buckets`, not `traffic_samples`.
- [ ] Stage only Step 3 source, tests, migration, routes, and this plan.
- [ ] Create exactly one local commit: `Phase 6K add traffic aggregation and ranking`.
- [ ] Verify commit hash and clean worktree; do not push.