# CosmicLink Phase 6C Safe Network Discovery Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Safely observe and adopt existing PPPoE network records without any network configuration mutation.

**Architecture:** Keep Phase 6B's `NetworkDriver` execution contract unchanged. Add a separate Go `DiscoveryProvider` and authenticated read-only discovery endpoint backed only by a deterministic `FakeDiscoveryProvider`. Laravel owns snapshots, discovered-resource fingerprints, reconciliation, explicit local-only adoption, tenant authorization, and discovery audit evidence; it never lets the browser contact Go.

**Tech Stack:** Laravel 12 / PHP 8.3, PostgreSQL-compatible JSON columns, Vue 3, Go standard-library HTTP/JSON, PHPUnit, Go tests.

**Spec:** User-provided “COSMICLINK — PHASE 6C NETWORK DISCOVERY & SAFE ADOPTION FOUNDATION” request dated September 17, 2026.

## Global Constraints

- Preserve `NetworkDriver`, `GoNetworkDriver`, and all Phase 6B mutation routes unchanged.
- Discovery and adoption must call no mutation handler or `NetworkDriver` method.
- Go supports only deterministic `FakeDiscoveryProvider`; no RouterOS/MikroTik library, protocol, or real device connection.
- Go discovery is authenticated, request-validated, provider-neutral, bounded, and secret-free.
- Laravel derives `tenant_id` solely from the authenticated user and authorizes every router/resource/connection relation.
- Snapshots are historical evidence; discovered resources upsert by `(tenant_id, router_id, resource_type, fingerprint)`.
- Newly seen resources start `DISCOVERED`; only an explicit operator action transitions a PPPoE account to `ADOPTED`; `MANAGED` is modeled but not enabled.
- Do not store or expose passwords, tokens, credentials, or provider raw payloads.
- Existing unrelated worktree files are excluded; work happens in `C:\xampp\cosmiclink-phase6c`.

---

### Task 1: Read-only Go discovery contract and fake provider

**Files:**
- Modify: `C:\xampp\cosmiclink-phase6c\network-engine\internal\network\types.go`
- Create: `C:\xampp\cosmiclink-phase6c\network-engine\internal\provider\fake_discovery.go`
- Modify: `C:\xampp\cosmiclink-phase6c\network-engine\internal\api\server.go`
- Modify: `C:\xampp\cosmiclink-phase6c\network-engine\cmd\server\main.go`
- Modify: `C:\xampp\cosmiclink-phase6c\network-engine\internal\api\server_test.go`

**Interfaces:**
- Produces `network.DiscoveryProvider`, `network.DiscoveryRequest`, `network.DiscoveryResult`, and `network.DiscoverySnapshot`.
- `api.New(provider network.Provider, discovery network.DiscoveryProvider, token string, logger *slog.Logger)` serves `POST /v1/discovery/routers/{router_ref}`.
- `provider.NewFakeDiscoveryProvider()` returns only fixed `CORE-01`, three profiles, three accounts, one pool, and deterministic queues. It excludes secret fields and never receives execution requests.

- [ ] **Step 1: Write focused Go tests** for bearer authentication, invalid JSON/route mismatch, unknown router, deterministic normalized response, secret absence, provider failure, and a fake-provider zero mutation count after discovery.
- [ ] **Step 2: Run the focused test** with `& 'C:\Program Files\Go\bin\go.exe' test ./internal/api` and confirm failures are from absent discovery behavior.
- [ ] **Step 3: Add the segregated read-only types, provider, and route.** The request contains exactly `tenant_ref` and `router_ref`; the route reference must match. The handler calls only `DiscoveryProvider.Discover`, logs no payload/secrets, and writes `success/provider/router_ref/discovered_at/snapshot`.
- [ ] **Step 4: Run `gofmt`, Go package tests, and vet.**

### Task 2: Laravel discovery persistence, client, and safe domain actions

**Files:**
- Create: `C:\xampp\cosmiclink-phase6c\database\migrations\2026_09_17_000024_create_network_discovery_tables.php`
- Create: `C:\xampp\cosmiclink-phase6c\app\Models\NetworkDiscoverySnapshot.php`
- Create: `C:\xampp\cosmiclink-phase6c\app\Models\DiscoveredNetworkResource.php`
- Create: `C:\xampp\cosmiclink-phase6c\app\Models\NetworkDiscoveryAudit.php`
- Create: `C:\xampp\cosmiclink-phase6c\app\Services\Network\NetworkDiscoveryClient.php`
- Create: `C:\xampp\cosmiclink-phase6c\app\Services\Network\GoNetworkDiscoveryClient.php`
- Create: `C:\xampp\cosmiclink-phase6c\app\Services\Network\DiscoveryResult.php`
- Create: `C:\xampp\cosmiclink-phase6c\app\Services\Network\NetworkDiscoveryService.php`
- Create: `C:\xampp\cosmiclink-phase6c\app\Services\Network\NetworkReconciliationService.php`
- Create: `C:\xampp\cosmiclink-phase6c\app\Services\Network\AdoptDiscoveredNetworkResource.php`
- Modify: `C:\xampp\cosmiclink-phase6c\app\Models\Router.php`
- Modify: `C:\xampp\cosmiclink-phase6c\app\Models\CustomerConnection.php`
- Modify: `C:\xampp\cosmiclink-phase6c\app\Providers\AppServiceProvider.php`
- Modify: `C:\xampp\cosmiclink-phase6c\config\network.php`
- Create: `C:\xampp\cosmiclink-phase6c\tests\Unit\GoNetworkDiscoveryClientTest.php`
- Create: `C:\xampp\cosmiclink-phase6c\tests\Feature\Phase6CNetworkDiscoveryTest.php`

**Interfaces:**
- `NetworkDiscoveryClient::discover(Router $router): DiscoveryResult` never exposes tenant input or secrets.
- `NetworkDiscoveryService::discover(Router $router, User $operator): NetworkDiscoverySnapshot` saves sanitized normalized data and resource upserts.
- `NetworkReconciliationService::reconcile(Router $router): Collection` classifies `NEW`, `MATCHED`, `CONFLICT`, `CHANGED`, `MISSING` without mutation.
- `AdoptDiscoveredNetworkResource::handle(DiscoveredNetworkResource $resource, CustomerConnection $connection, User $operator): DiscoveredNetworkResource` creates/links a local `NetworkAccount`, preserves provider config, changes state only to `ADOPTED`, and records a discovery audit entry.

- [ ] **Step 1: Write failing Laravel tests** for HTTP success/failure/redaction, snapshot persistence, deterministic fingerprinting, resource idempotency, all five reconciliation statuses, local-only adoption, cross-tenant blocks, and zero `network_operation_logs` during discovery/reconcile/adoption.
- [ ] **Step 2: Run focused PHPUnit tests** and confirm the missing service/model failures.
- [ ] **Step 3: Add the reversible schema and model relationships.** Snapshot payload and normalized data use JSON. Resource fingerprint is SHA-256 of canonical stable, secret-stripped normalized fields. The audit records discovery/adoption actor, router/resource, action, safe summary, and timestamps.
- [ ] **Step 4: Implement the separate Go client and services.** Transport reuses Go URL/token/timeouts but not `GoNetworkDriver`; connection/malformed failures are normalized. Persist `failed` snapshots and audits on controlled client failures. No service calls `NetworkDriver` or `NetworkOperationService`.
- [ ] **Step 5: Run focused tests green.**

### Task 3: Thin Laravel application routes and minimal operational UI

**Files:**
- Create: `C:\xampp\cosmiclink-phase6c\app\Http\Controllers\NetworkDiscoveryController.php`
- Modify: `C:\xampp\cosmiclink-phase6c\routes\web.php`
- Create: `C:\xampp\cosmiclink-phase6c\resources\views\network\discovery.blade.php`
- Modify: `C:\xampp\cosmiclink-phase6c\resources\js\components\AppShell.vue`

- [ ] **Step 1: Write feature expectations** that tenant A sees only its own discovery data, can run its own router discovery, and receives 403 for tenant B resource adoption.
- [ ] **Step 2: Add thin authenticated controller actions** for index, `POST /network/discovery/routers/{router}`, and `POST /network/discovery/resources/{resource}/adopt`, authorizing server-side ownership before calling services.
- [ ] **Step 3: Add a restrained server-rendered view.** It must visibly state `[READ-ONLY] No router configuration will be modified`, show latest snapshot/resource counts and reconciliation status, and label adoption as local mapping—not sync. It must have no manage/push/fix/delete actions.
- [ ] **Step 4: Add one `Discovery` link/title to the existing Network shell, without redesigning it.**
- [ ] **Step 5: Run the focused feature tests and `npm run build`.**

### Task 4: Documentation, live proof, and regression

**Files:**
- Modify: `C:\xampp\cosmiclink-phase6c\docs\GO_NETWORK_ENGINE.md`
- Create: `C:\xampp\cosmiclink-phase6c\docs\SAFE_ISP_MIGRATION.md`

- [ ] **Step 1: Document** CONNECT → DISCOVER → COMPARE → IMPORT/ADOPT → MANAGE (future), exact responsibility boundaries, state model, snapshots/fingerprints, reconciliation, secret handling, tenant isolation, fake-only status, and the read-only guarantee.
- [ ] **Step 2: Start the Go engine using `FakeProvider` plus `FakeDiscoveryProvider`; run a live Laravel test** with `NETWORK_ENGINE_LIVE_TEST=1` that proves snapshot/resource persistence, repeat idempotency, adoption, stable reconciliation, and the Go mutation counter remains `0`.
- [ ] **Step 3: Run all required gates:** `gofmt`, `go test ./...`, `go vet ./...`, `php artisan test`, `vendor\bin\pint --test`, `npm run build`, `git diff --check`, and Phase 6B live integration where practical.
- [ ] **Step 4: Inspect all changed files and the staged diff.** Commit only Phase 6C files with message `CosmicLink Phase 6C verified safe network discovery foundation`; do not push.