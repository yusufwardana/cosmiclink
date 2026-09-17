# CosmicLink Phase 6B Go Network Engine Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a standalone, provider-neutral Go HTTP execution boundary behind Laravel's existing `NetworkDriver` contract.

**Architecture:** Laravel retains authorization, lifecycle decisions, persistence, and audit records. `GoNetworkDriver` turns the existing six driver calls into authenticated HTTP/JSON commands; the Go server validates and normalizes execution through `NetworkProvider`, initially backed only by deterministic in-memory `FakeProvider` state.

**Tech Stack:** Laravel 12 / PHP 8.3, Go standard-library HTTP/JSON, PHPUnit, Go testing.

**Spec:** User-provided “COSMICLINK — PHASE 6B GO NETWORK ENGINE FOUNDATION” request dated September 17, 2026.

## Global Constraints

- Do not change the frozen Vue/CosmicLabs frontend.
- Do not implement RouterOS, MikroTik libraries, gRPC, polling, discovery, or business decisions in Go.
- Existing `FakeNetworkDriver` remains supported by `NETWORK_DRIVER=fake`.
- Authenticated mutation endpoints require `Authorization: Bearer <shared token>`.
- Never log service tokens, router credentials, or PPPoE passwords.
- Go idempotency is process-local and non-persistent in Phase 6B.

---

### Task 1: Laravel Go driver contract

**Files:**
- Create: `c:\xampp\htdocs\cosmiclink\app\Services\Network\GoNetworkDriver.php`
- Modify: `c:\xampp\htdocs\cosmiclink\config\network.php`
- Modify: `c:\xampp\htdocs\cosmiclink\app\Providers\AppServiceProvider.php`
- Modify: `c:\xampp\htdocs\cosmiclink\.env.example`
- Test: `c:\xampp\htdocs\cosmiclink\tests\Unit\GoNetworkDriverTest.php`

- [ ] Write failing HTTP-facade tests for success, bearer auth, operation/idempotency propagation, structured failures, malformed responses, timeouts, connection failures, and password redaction.
- [ ] Implement the minimal adapter using `Http::connectTimeout()->timeout()` and response normalization.
- [ ] Bind only `fake` and `go`; reject other driver values.
- [ ] Run `php artisan test tests/Unit/GoNetworkDriverTest.php`.

### Task 2: Standalone Go engine

**Files:**
- Create: `c:\xampp\htdocs\cosmiclink\network-engine\go.mod`
- Create: `c:\xampp\htdocs\cosmiclink\network-engine\cmd\server\main.go`
- Create: `c:\xampp\htdocs\cosmiclink\network-engine\internal\config\config.go`
- Create: `c:\xampp\htdocs\cosmiclink\network-engine\internal\network\types.go`
- Create: `c:\xampp\htdocs\cosmiclink\network-engine\internal\provider\provider.go`
- Create: `c:\xampp\htdocs\cosmiclink\network-engine\internal\provider\fake.go`
- Create: `c:\xampp\htdocs\cosmiclink\network-engine\internal\api\server.go`
- Test: `c:\xampp\htdocs\cosmiclink\network-engine\internal\api\server_test.go`

- [ ] Write endpoint tests for health, auth, validation, test, create, duplicate create/idempotency, disable/repeated disable, enable, profile change, disconnect, missing account, injected provider failure, and redacted logs.
- [ ] Implement provider-neutral routes and bounded request body decoding.
- [ ] Implement in-memory mutex-protected idempotency caching and deterministic fake state.
- [ ] Run `go test ./...` and `go vet ./...` from `network-engine`.

### Task 3: Documentation and regression verification

**Files:**
- Create: `c:\xampp\htdocs\cosmiclink\docs\GO_NETWORK_ENGINE.md`

- [ ] Document the Laravel/Go responsibility boundary, variables, startup, routes, auth, idempotency behavior, timeouts, and Phase 6B limitations.
- [ ] Run focused and full PHPUnit, Pint, Vite build, Go checks, `git diff --check`, and local `NETWORK_DRIVER=fake` plus `NETWORK_DRIVER=go` workflows.
- [ ] Commit only if every required verification passes, excluding pre-existing unrelated worktree edits.