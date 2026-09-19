# CosmicLink Phase 6I — Controlled RouterOS Operations Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. This document is an audit and implementation plan only; it is not approval to execute RouterOS writes.

**Goal:** Establish the first narrowly bounded, explicitly authorized, fail-closed RouterOS write boundary for adopted CosmicLink network accounts without implementing Phase 6I during this audit.

**Architecture:** Laravel remains the business authorization, lifecycle, tenant, and audit authority. The existing `NetworkOperationService` and `NetworkDriver` boundary are hardened rather than duplicated. Go remains an execution boundary only, with a separate typed RouterOS mutation provider that cannot execute arbitrary commands.

**Tech Stack:** Laravel 12 / PHP 8.3, Eloquent, PHPUnit, Go 1.22, `github.com/go-routeros/routeros/v3`, RouterOS API/API-SSL compatible with RouterOS 6.49.13, existing Blade/CosmicLink design system, existing FakeNetworkDriver and FakeProvider.

**Spec:** This audit request; verified Phase 6H baseline commit `55fe60aa5fe999053f0fbf832ed7c66b3a7856b5`.

## Global Constraints

- Audit and design only; do not implement Phase 6I production behavior in this work.
- Worktree: `C:\xampp\htdocs\cosmiclink-phase6i`; branch: `phase-6i-controlled-routeros-operations`.
- Baseline `HEAD` must remain `55fe60aa5fe999053f0fbf832ed7c66b3a7856b5`.
- MikroTik is read-only throughout this audit; RouterOS writes during audit: `0`.
- Do not modify production Laravel, Go, Vue, migrations, RouterOS configuration, RouterOS permissions, or database schema in this audit.
- Do not enable `MANAGED`, execute billing mutations, create RouterOS test resources, commit, push, or merge.
- RouterOS target is MikroTik hEX, RouterOS `6.49.13` long-term; do not use RouterOS v7 REST semantics.
- Browser/API callers submit business intent only; no raw RouterOS command, path, selector, or tenant authority is accepted.
- Fake simulation remains permanent and must never silently fall back from real execution.

---

## 1. Executive Decision Summary

**PHASE 6I PLAN — READY.** Implementation should begin only after a separate approval. The safe MVP is manual-only and contains exactly three real business operations:

```text
ENABLE_PPPOE
DISABLE_PPPOE
DISCONNECT_SESSION
```

`MANAGED` is an explicit, account-scoped authorization for those operations only. It is not unrestricted router access, and it does not imply that the account is online or that the router is healthy.

An adopted account with `NetworkAccount.encrypted_secret = NULL` may become `MANAGED` for this limited set because none of the three operations needs the PPPoE password. Password changes, creation, deletion, profile, queues, firewall, NAT, routing, VLAN, DHCP, DNS, RouterOS users/services, reboot, scripts, imports, restores, and arbitrary commands remain forbidden.

The global real-mutation switch is off by default. Real execution also requires explicit operator authorization, tenant ownership, `MANAGED`, adopted evidence, fresh `MATCHED` reconciliation, fresh healthy router evidence, stable identity, an exact allowlisted operation, a successful lock/idempotency reservation, and matching preflight/postflight evidence.

Phase 2 billing automation must not perform real writes in Phase 6I. Unknown network outcomes are never blindly retried or compensated. The implementation requires an additive database migration; no migration is created during this audit.

## 2. Verified Phase 6H Baseline

The isolated worktree was created directly from:

```text
55fe60aa5fe999053f0fbf832ed7c66b3a7856b5
CosmicLink Phase 6H verified safe reconciliation and adoption
```

Verified:

```text
worktree: C:\xampp\htdocs\cosmiclink-phase6i
branch:   phase-6i-controlled-routeros-operations
HEAD:     55fe60aa5fe999053f0fbf832ed7c66b3a7856b5
```

Phase 6H guarantees:

```text
DISCOVERED --explicit operator adoption--> ADOPTED
```

with RouterOS writes `0`, PPPoE changes `0`, MANAGED transitions `0`, and automatic adoption `0`. The authoritative relationship is:

```text
CustomerConnection.network_account_id -> NetworkAccount
```

`DiscoveredNetworkResource` remains discovery/reconciliation evidence. `AdoptDiscoveredNetworkResource` uses tenant/router checks, row locks, a 24-hour freshness check, and local-only updates. Existing discovered credentials are valid with `encrypted_secret = NULL`; Phase 6H does not retrieve or infer passwords.

## 3. Existing Mutation Architecture Audit

The actual shared path is:

```text
Controller or Action
  -> NetworkOperationService
  -> NetworkDriver
  -> FakeNetworkDriver or GoNetworkDriver
  -> Go HTTP API
  -> Go Provider
```

`NetworkOperationService` currently exposes connection test, account creation, enable/disable through `changeStatus`, profile change, and disconnect. It writes `NetworkOperationLog` after the driver returns, but does not currently perform Phase 6I management-state, reconciliation, freshness, health, kill-switch, preflight, postflight, durable idempotency, or unknown-outcome checks.

Current callers include:

- `ProvisionCustomerConnection` -> `createAccount`;
- `SuspendCustomerConnection` -> disable;
- `ReactivateCustomerConnection` -> enable;
- `NetworkAccountController` -> create, enable, disable, profile, disconnect;
- Phase 6B live integration -> provisioning, billing suspension, and payment reactivation through Go.

Do **not** create `NetworkOperationServiceV2`, `NetworkDriverV2`, or a parallel audit log. Harden the existing service with explicit controlled-operation methods and a centralized gate. Keep provisioning/profile APIs separate and unavailable for real Phase 6I control.

## 4. Existing Phase 2 Billing Automation Risk Audit

The current automatic overdue path is:

```text
EnforceBillingCommand
  -> ProcessOverdueBilling
  -> SuspendCustomerConnection
  -> NetworkOperationService::changeStatus(DISABLE_PPPOE)
  -> NetworkDriver
```

Payment settlement can use:

```text
RecordPayment
  -> ReactivateCustomerConnection
  -> NetworkOperationService::changeStatus(ENABLE_PPPOE)
  -> NetworkDriver
```

Phase 6H currently prevents adopted accounts from these paths using `metadata['adopted_from_discovery']`, but that is not enough for Phase 6I. It is duplicated, does not model MANAGED, does not provide a kill switch, and does not prevent future billing callers from reaching the driver.

### Exact Phase 6I guard location

The first mandatory guard is inside the existing shared `NetworkOperationService` real-operation path, before the driver callback. Billing actions must also be isolated at their own boundary so they never request a Phase 6I real controlled operation:

```text
billing action -> billing isolation guard -> no real controlled operation
any manual caller -> NetworkOperationService gate -> driver only if all gates pass
```

`ProcessOverdueBilling`, `SuspendCustomerConnection`, `ReactivateCustomerConnection`, `RecordPayment`, scheduled commands, and payment/provider events remain business-state/simulation paths only during Phase 6I. Tests must prove overdue and payment flows create zero real mutation requests.

## 5. Existing Phase 6B Go Mutation Contract Audit

The Go engine already provides:

- `network.Request` with operation ID, idempotency key, tenant ref, router ref, account ref, and parameters;
- normalized `network.Result`;
- typed `network.Provider` methods;
- bearer authentication, bounded request bodies, and unknown-field rejection;
- an in-memory idempotency store;
- `FakeProvider` state and mutation counting;
- structured errors.

Required hardening:

- remove real Phase 6I access to create/profile operations;
- reject unknown operations explicitly; never default an unknown operation to disconnect;
- validate route/operation pairing and request digest on idempotency reuse;
- distinguish replayed success/failure/unknown;
- add a separate RouterOS mutation provider rather than contaminating discovery/monitoring providers;
- keep Laravel responsible for authorization, billing, MANAGED state, reconciliation, and tenant policy.

The current in-memory Go idempotency map is useful for tests but is not restart durable. Phase 6J mass automation requires a durable strategy; Phase 6I must not pretend the in-memory map is sufficient for crash recovery.

## 6. Current RouterOS Provider Architecture

The worktree contains separate read-only providers:

- `network-engine/internal/provider/routeros_discovery.go`: system identity/resource, PPP profiles/secrets, IP pools, simple queues;
- `network-engine/internal/monitoring/routeros.go`: system resource/identity and active PPP sessions.

Both use RouterOS API/API-SSL, typed read transport, timeouts, and secret redaction. Existing tests prove mutation commands are rejected before transmission.

Phase 6I must add a separate typed mutation provider, such as:

```text
C:\xampp\htdocs\cosmiclink-phase6i\network-engine\internal\provider\routeros_operations.go
C:\xampp\htdocs\cosmiclink-phase6i\network-engine\internal\provider\routeros_operations_test.go
```

It must not add writes to discovery or monitoring providers and must not expose `execute(command)`, `run(path)`, `sendRawCommand`, or any equivalent. RouterOS API/API-SSL is the correct v6 boundary; RouterOS v7 REST must not be used for the hEX target. The implementation must validate the exact RouterOS 6.49.13 sentence behavior against authoritative v6 documentation or a safe test target before any hardware acceptance.

## 7. Management-State Semantics

| State | Meaning | Real writable? |
|---|---|---:|
| `DISCOVERED` | Evidence exists without explicit local relationship | No |
| `ADOPTED` | Operator linked existing evidence to a local connection/account; RouterOS untouched | No |
| `MANAGED` | Operator authorized the exact Phase 6I allowlist for this account/router | Only after every gate |
| revoked | Authorization removed; adopted evidence/history preserved | No |

Health is separate:

```text
agent health != router health != customer health != management authorization
ONLINE != MANAGED
MANAGED != ONLINE
ADOPTED != writable
```

The recommended implementation adds explicit `network_accounts` fields: `management_state`, `managed_at`, `managed_by_user_id`, `revoked_at`, `revoked_by_user_id`, and immutable `management_scope` JSON. Adopted evidence remains required; account metadata must not be the authorization ledger.

## 8. ADOPTED → MANAGED Design

The transition is manual, two-step, policy-authorized, and local-only. It must not call RouterOS.

### Required preconditions

All must be true inside a transaction with row locks:

1. actor has a dedicated `manageNetworkAccount` capability;
2. account, connection, router, and resource belong to the actor's tenant;
3. `CustomerConnection.network_account_id` is this account;
4. account, connection, and adopted resource point to the same router;
5. resource is `ADOPTED` and points to the exact connection/account;
6. latest successful discovery is fresh, initially the Phase 6H 24-hour threshold;
7. reconciliation is exactly `MATCHED`;
8. stable external identity exists (`.id` preferred, validated username fallback only if explicitly supported);
9. RouterOS version is reported and compatible with the provider;
10. latest router health is fresh and `ONLINE`, not `UNKNOWN`, `OFFLINE`, or `DEGRADED`;
11. no open unknown, postflight-mismatch, or concurrent operation exists;
12. operator explicitly confirms the immutable three-operation scope.

The UI confirmation must show customer, username, router identity/version, evidence timestamp, reconciliation, router health, unknown-password behavior, allowed operations, and forbidden operations. No browser field can expand the scope.

## 9. MANAGED → ADOPTED Revocation

With `manageNetworkAccount` authorization, lock the account/evidence rows, set `MANAGED` to `ADOPTED`, clear managed actor/time, record revocation actor/time/reason, preserve the connection and evidence, and append an audit record. Make zero RouterOS calls. Future requests fail the Laravel gate, including stale browser submissions.

## 10. Authorization and Permissions

Current policies are tenant-scoped but do not distinguish ordinary customer access from network-write authority, and no Spatie permission package is present. Add explicit policy/capability methods aligned with current Laravel policies:

```text
manageNetworkAccount(User, NetworkAccount)
executeNetworkOperation(User, CustomerConnection, operation)
viewNetworkOperationLog(User, NetworkOperationLog)
```

Management enable/revoke requires stronger authority than viewing/editing customers. Tenant checks and capability checks must happen in policy and service. Do not trust caller-provided `tenant_id`.

## 11. Global Mutation Kill Switch

Safe default:

```text
NETWORK_MUTATIONS_ENABLED=false
```

Laravel's centralized controlled-operation gate must enforce it. The Go engine should independently default mutations off, for example `NETWORK_ENGINE_MUTATIONS_ENABLED=false`. Effective real execution requires both enabled. When off, return `MUTATIONS_DISABLED`, record a bounded denied attempt if configured, and do not send a mutation request. Discovery, monitoring, and FakeNetworkDriver remain available.

The UI/API must show the switch state explicitly; selecting `NETWORK_DRIVER=go` is not permission to write.

## 12. Exact Initial Operation Allowlist

Only these business operations are allowed:

| Operation | Purpose | Write family | Password required? |
|---|---|---|---:|
| `ENABLE_PPPOE` | Permit an existing secret to authenticate | `/ppp/secret/set` with `disabled=no` | No |
| `DISABLE_PPPOE` | Prevent an existing secret from authenticating | `/ppp/secret/set` with `disabled=yes` | No |
| `DISCONNECT_SESSION` | End one current PPPoE session without disabling the secret | `/ppp/active/remove` | No |

Required safe reads are hardcoded to:

```text
/system/identity/print
/system/resource/print
/ppp/secret/print
/ppp/active/print
```

Allowed writes are exactly:

```text
/ppp/secret/set  =.id=<preflight-id> =disabled=yes
/ppp/secret/set  =.id=<preflight-id> =disabled=no
/ppp/active/remove =.id=<preflight-session-id>
```

The provider constructs these internally from structured fields. Everything else is forbidden.

### Operation rules

**Disable:** preflight one exact secret; already disabled is successful no-op; otherwise set `disabled=yes`; postflight same identity must show disabled. No blind retry or automatic enable compensation.

**Enable:** preflight one exact secret; already enabled is successful no-op; otherwise set `disabled=no`; postflight same identity must show enabled. No blind retry or automatic disable compensation.

**Disconnect:** preflight exact PPPoE active sessions; zero is successful no-op; more than one is blocked in the MVP; one session may be removed by its preflight `.id`; postflight confirms that target session is absent. A new session may reconnect immediately, so disconnect is transient and never equivalent to disable.

## 13. Explicitly Deferred Operations

Forbidden in Phase 6I: `CREATE_PPPOE`, delete secret, password changes, profile changes, queues, firewall, NAT, routes, VLAN, DHCP, DNS, RouterOS users/groups/permissions/services, reboot, scripts, scheduler, imports, restores, and arbitrary commands. Existing interface methods do not make an operation safe or approved.

## 14. RouterOS v6 Command Mapping

Typed provider methods should conceptually perform:

```text
/ppp/secret/print
?.proplist=.id,name,profile,disabled,service
?name=<validated-username>

/ppp/secret/set
=.id=<resolved-id>
=disabled=yes|no

/ppp/active/print
?.proplist=.id,name,service,address,uptime
?name=<validated-username>

/ppp/active/remove
=.id=<resolved-session-id>
```

`.id` is resolved during preflight, never trusted from the browser or stale discovery. Username is an identity assertion/filter, not a raw command selector. Validate length, encoding, and control characters before building API words. No password field is ever requested or stored. The implementation must validate the PPP secret `disabled` behavior and CLI-like API sentence construction against authoritative RouterOS 6.49.13 documentation or a safe test target.

## 15. Laravel Operation Pipeline

```text
Operator
 -> Customer 360 / Network Account business action
 -> Laravel policy
 -> tenant/account/connection resolution
 -> global kill switch
 -> MANAGED check
 -> adopted evidence/reconciliation/freshness
 -> router health/version
 -> exact operation allowlist
 -> idempotency reservation and lock
 -> narrow preflight
 -> compare-and-execute
 -> postflight
 -> NetworkOperationLog
```

Controllers and APIs pass only a typed operation request and client idempotency key. The service resolves router, account identity, evidence, and provider. Billing and future jobs cannot call the driver directly.

## 16. Go Operation Pipeline

```text
authenticated Laravel request
 -> strict decode and unknown-field rejection
 -> route/operation match
 -> exact allowlist validation
 -> structured request validation
 -> idempotency digest check
 -> typed provider method
 -> RouterOS preflight
 -> at most one approved write
 -> RouterOS postflight
 -> sanitized result
```

Go does not evaluate invoices, MANAGED, reconciliation, or tenant policy. Unknown operation must be rejected, not mapped to disconnect.

## 17. RouterOS Write-Provider Design

Add a separate provider contract, for example:

```go
type MutationProvider interface {
    Name() string
    EnableAccount(context.Context, MutationRequest) Result
    DisableAccount(context.Context, MutationRequest) Result
    DisconnectSession(context.Context, MutationRequest) Result
}
```

The real provider owns command constants, typed transport methods, API/API-SSL timeouts, auth classification, cancellation, redaction, safe evidence, mutation counters in fakes, and `UNKNOWN_OUTCOME`. Provider selection must be explicit. Go failure is never converted to FakeNetworkDriver success.

## 18. Preflight Design

Capture safe evidence only:

```json
{
  "router_identity": "edge-01",
  "routeros_version": "6.49.13",
  "account_username": "pppoe-yusuf",
  "secret_external_ref": "*2",
  "secret_disabled": false,
  "secret_profile": "HOME-20M",
  "active_session_ids": ["*7"],
  "observed_at": "2026-09-18T00:00:00Z"
}
```

Never capture PPPoE password, RouterOS credentials, Authorization header, Go token, or raw sensitive errors. Use a narrow operation-specific preflight; do not run full discovery for every click.

## 19. Compare-and-Execute

The operator's expected safe state is compared to a fresh read immediately before the write:

```text
read current identity/state
 -> verify exact account/router/external identity
 -> verify expected current state or desired-state no-op
 -> issue one hardcoded mutation
 -> read same identity
 -> verify expected result
```

If the account changed between discovery and preflight, return `PRE_FLIGHT_IDENTITY_MISMATCH` or `PRECONDITION_FAILED`; do not repair drift automatically.

## 20. Postflight Verification

For enable/disable, the only expected delta is the target secret's `disabled` value. Username, `.id`, profile, service, router identity, and unrelated resources must remain unchanged. For disconnect, only the targeted active session's disappearance is expected; reconnection is recorded as transient state, not treated as permission to disable.

Results are `SUCCESS`, `IDEMPOTENT_NOOP`, `POSTFLIGHT_MISMATCH`, or `UNKNOWN_OUTCOME`.

## 21. Reconciliation Safety Gate

Real mutation requires:

```text
account.management_state == MANAGED
adopted evidence points to exact account/connection/router
reconciliation == MATCHED
successful discovery evidence is fresh
preflight identity matches evidence
```

`DISCOVERED`, `ADOPTED` without MANAGED, `NEW`, `MISSING`, `CHANGED`, `CONFLICT`, stale/failed discovery, and ambiguous identity all block. Do not automatically rediscover, adopt, relink, overwrite, or repair drift before the requested operation.

## 22. Router-Health Safety Gate

Use router `HealthObservation`, not customer health. Require a fresh observation with:

```text
health_state == ONLINE
reachable == true
within mutation-health window
```

Missing, stale, `UNKNOWN`, `OFFLINE`, `DEGRADED`, monitoring failure, or an active router outage incident blocks. Customer online does not override router unknown; router online does not mean customer online or managed.

## 23. Unknown-Password Behavior

**Yes:** `encrypted_secret = NULL` accounts can become MANAGED for enable, disable, and disconnect. These operations change the secret's disabled flag or remove a runtime session; they do not require PPPoE authentication material. No password extraction from RouterOS is permitted. The exact v6 behavior must be validated before real hardware acceptance.

Account creation, password changes, and synchronization remain forbidden. Discovery, preflight, logs, and Go responses must redact password fields even if RouterOS returns them. The exact RouterOS v6 behavior must be validated against authoritative documentation before real hardware acceptance.

## 24. Idempotency Design

Laravel stores a client idempotency key for each controlled operation with a tenant-scoped uniqueness constraint and a request digest. Same key/same request returns the original result; same key/different operation/account/expected state returns `IDEMPOTENCY_CONFLICT`.

Go retains its existing store but adds request digest and scoped key validation. The current in-memory store is test-safe but not restart durable; durable cross-process recovery is required before Phase 6J mass automation.

State behavior:

- already disabled/enabled: successful no-op, zero write sentence;
- no active session for disconnect: successful no-op;
- duplicate browser request: one logical operation;
- timeout after possible mutation: postflight/reconciliation, never blind retry.

## 25. Concurrency and Locking

Use database transactions/row locks for management transitions, revocation, idempotency reservation, and one active operation per account. Prefer serializing initial operations per router. Do not hold a database transaction across a RouterOS network wait. Store operation states such as `preflighting`, `executing`, `verifying`, `success`, `failed`, `unknown`, and `resolved`; block new writes while an operation is unknown.

## 26. Failure Taxonomy

Use bounded codes aligned across Laravel and Go:

```text
NOT_MANAGED, MANAGEMENT_REVOKED, MUTATIONS_DISABLED,
OPERATION_NOT_ALLOWED, TENANT_MISMATCH, RECONCILIATION_NOT_MATCHED,
STALE_DISCOVERY, ROUTER_HEALTH_UNKNOWN, ROUTER_UNAVAILABLE,
ROUTER_AUTH_FAILED, ROUTER_PROTOCOL_ERROR, ACCOUNT_NOT_FOUND,
AMBIGUOUS_ACCOUNT_IDENTITY, SESSION_NOT_FOUND, MULTIPLE_SESSIONS,
PRECONDITION_FAILED, PRE_FLIGHT_IDENTITY_MISMATCH, ENGINE_UNAVAILABLE,
ENGINE_AUTH_FAILED, NETWORK_TIMEOUT, UNKNOWN_OUTCOME,
POSTFLIGHT_MISMATCH, IDEMPOTENCY_CONFLICT, CONCURRENT_OPERATION,
CAPACITY_LIMIT
```

Do not expose raw RouterOS errors, credentials, tokens, passwords, or stack traces.

## 27. Unknown-Outcome Handling

On timeout/connection reset after a write may have been sent:

1. record `UNKNOWN_OUTCOME`;
2. do not retry or issue the opposite command;
3. attempt one bounded safe postflight read;
4. close as recovered success only if expected state is confirmed;
5. otherwise retain unknown and block further writes for the account;
6. require operator refresh/reconciliation/manual resolution.

## 28. Retry Policy

No automatic mutation retry after timeout, cancellation, reset, malformed response, or uncertain dispatch. Only bounded read-only preflight/postflight reads may retry under explicit deadlines. HTTP mutation POST retries are disabled unless dispatch non-execution can be proven; default is no retry.

## 29. Rollback and Compensation

Do not promise automatic rollback. Known successful disable/enable can be reversed only by a later explicit operator action after fresh gates. Disconnect has no rollback. Unknown outcomes never receive an automatic opposite command. Postflight mismatch blocks and requires review.

## 30. NetworkOperationLog Audit

Current `NetworkOperationLog` is useful but insufficient: it has tenant, router, initiating user, operation, target, request/result JSON, status, error, and timestamps, but no durable account ID, idempotency key, provider/mode, pre/post evidence, request digest, or unknown resolution.

Implementation should add first-class fields for:

- `network_account_id`;
- `idempotency_key` and `request_digest`;
- `provider` and `execution_mode` (`simulation`/`real`);
- states `denied`, `preflighting`, `executing`, `verifying`, `success`, `failed`, `unknown`, `resolved`;
- `preflight_evidence`, `postflight_evidence`, `failure_code`;
- resolver actor/time/note for unknown outcomes.

Existing payload fields may remain for compatibility, but controlled evidence must be versioned and sanitized. Never log RouterOS password, PPPoE password, Authorization header, service token, or raw sensitive command/error.

## 31. Secret Sanitization

Recursively remove:

```text
password, pass, secret, token, authorization,
credential, credentials, encrypted_secret, encrypted_credentials
```

Keep the RouterOS client logger silent/redacted, do not log Laravel HTTP headers/bodies for Go calls, and permit only safe identity/state evidence.

## 32. Tenant Isolation

Laravel derives tenant from `Auth::user()->tenant_id`. Every account, connection, router, discovered resource, health observation, operation log, and idempotency key must be tenant-scoped. Caller-supplied `tenant_id` is rejected/ignored. Go receives references for correlation, not authorization.

## 33. Simulation/Real Separation

```text
SIMULATION -> FakeNetworkDriver -> deterministic local result
REAL       -> GoNetworkDriver -> Go engine -> RouterOS mutation provider
```

Every result and confirmation shows `[SIMULATION]` or `[REAL NETWORK]`. Engine failure in real mode is an error, never fake success. Fake mutation counters remain part of tests.

## 34. Billing Automation Isolation

Phase 6I does not activate Smart Auto-Isolation. Keep invoice/payment state transitions, but prevent `ProcessOverdueBilling`, `SuspendCustomerConnection`, `ReactivateCustomerConnection`, `RecordPayment`, scheduler commands, and provider events from requesting real controlled operations. `NETWORK_DRIVER=go` must not turn billing into real writes. Add tests for overdue and payment zero real mutation requests and zero real operation logs.

## 35. Circuit-Breaker-Ready Design

Preserve a future service boundary for maximum operations/router/time window, maximum concurrent operations, per-router lock, consecutive failure threshold, unknown-outcome threshold, tenant/global disable, and manual reset. Initial implementation may keep limits conservative or disabled, but a mutation must be denied if the safety policy cannot evaluate. Do not embed direct driver calls in future mass jobs.

## 36. UI Safety

The design system is frozen. Show business actions only:

- Disable internet access;
- Enable internet access;
- Disconnect current session.

Never show “Run RouterOS command.” Show mode, customer, username, router identity/version, management state, reconciliation/freshness, health/freshness, exact scope, unknown-password behavior, and forbidden operations. Disable dangerous actions server-side as well as visually.

## 37. Customer 360 Changes

Add a compact network-control card, not a router console:

```text
Management: MANAGED / ADOPTED / unavailable
Router health: ONLINE / OFFLINE / UNKNOWN
Reconciliation: MATCHED / blocked state
Execution mode: SIMULATION / REAL NETWORK
Allowed actions: three explicit business actions
Last operation: bounded status/time
```

Do not expose router credentials, passwords, raw commands, arbitrary profiles, or bulk controls.

## 38. API Contract

Align with the existing authenticated `/api/v1` style:

```text
POST /api/v1/network-accounts/{account}/management/enable
POST /api/v1/network-accounts/{account}/management/revoke
POST /api/v1/network-accounts/{account}/operations/enable
POST /api/v1/network-accounts/{account}/operations/disable
POST /api/v1/network-accounts/{account}/operations/disconnect
GET  /api/v1/network-accounts/{account}/operations/{operation}
```

Permitted request data is limited to an opaque idempotency key, expected safe state, and explicit confirmation. Reject `tenant_id`, RouterOS command/path, provider, driver, password, profile, external selector, and arbitrary operation fields. Web and API must call the same service.

## 39. Database Changes: YES

Existing `network_accounts` has no explicit management state or managed actor/time. Existing `network_operation_logs` has no account ID, durable idempotency, provider/mode, evidence, or unknown resolution. Free-form metadata is not an authorization ledger, and Go idempotency is in-memory. Therefore implementation needs an additive migration; this audit creates none.

Backfill rules must be safe: no account becomes MANAGED, no secret changes, no RouterOS calls, and only accounts with exact Phase 6H adopted evidence become `ADOPTED`; other legacy accounts remain non-managed/simulation-only.

## 40. Laravel Files Expected to Change

```text
C:\xampp\htdocs\cosmiclink-phase6i\app\Models\NetworkAccount.php
C:\xampp\htdocs\cosmiclink-phase6i\app\Models\NetworkOperationLog.php
C:\xampp\htdocs\cosmiclink-phase6i\app\Policies\NetworkAccountPolicy.php                 (new)
C:\xampp\htdocs\cosmiclink-phase6i\app\Policies\NetworkOperationPolicy.php              (new)
C:\xampp\htdocs\cosmiclink-phase6i\app\Providers\AppServiceProvider.php
C:\xampp\htdocs\cosmiclink-phase6i\app\Services\Network\NetworkDriver.php                 (interface: no key/mode/user today)
C:\xampp\htdocs\cosmiclink-phase6i\app\Services\Network\NetworkOperationService.php
C:\xampp\htdocs\cosmiclink-phase6i\app\Services\Network\NetworkOperationResult.php        (unknown outcome representation)
C:\xampp\htdocs\cosmiclink-phase6i\app\Services\Network\GoNetworkDriver.php               (derived key, no credentials)
C:\xampp\htdocs\cosmiclink-phase6i\app\Services\Network\FakeNetworkDriver.php             (must stay simulation-only)
C:\xampp\htdocs\cosmiclink-phase6i\app\Services\Network\ControlledNetworkOperationGate.php (new)
C:\xampp\htdocs\cosmiclink-phase6i\app\Services\Network\NetworkOperationRequest.php      (new)
C:\xampp\htdocs\cosmiclink-phase6i\app\Http\Controllers\NetworkAccountController.php
C:\xampp\htdocs\cosmiclink-phase6i\app\Http\Controllers\Api\V1\ApiController.php or dedicated controller
C:\xampp\htdocs\cosmiclink-phase6i\app\Actions\ProcessOverdueBilling.php
C:\xampp\htdocs\cosmiclink-phase6i\app\Actions\SuspendCustomerConnection.php
C:\xampp\htdocs\cosmiclink-phase6i\app\Actions\ReactivateCustomerConnection.php
C:\xampp\htdocs\cosmiclink-phase6i\app\Actions\RecordPayment.php
C:\xampp\htdocs\cosmiclink-phase6i\config\network.php
C:\xampp\htdocs\cosmiclink-phase6i\.env.example
C:\xampp\htdocs\cosmiclink-phase6i\routes\web.php
C:\xampp\htdocs\cosmiclink-phase6i\routes\api.php
C:\xampp\htdocs\cosmiclink-phase6i\tests\Unit\GoNetworkDriverTest.php                    (asserts current key shape)
```

Add a migration only during approved implementation. The newest baseline migration is `database/migrations/2026_09_18_000028_add_network_agent_leases.php`; the Phase 6I migration must sort after it (for example `2026_09_18_000029_add_controlled_network_operations.php` or a later date) and must be additive only.

Changing `NetworkDriver` is a cross-cutting edit: it has exactly two implementations (`FakeNetworkDriver`, `GoNetworkDriver`) bound in `AppServiceProvider::register()` by `config('network.driver')` (`fake` / `go`), and every implementation plus `NetworkOperationService` and the existing `tests/Unit/GoNetworkDriverTest.php` payload assertions must move together. Keep `testConnection` outside the controlled path if Section 13's read-only test-connection carve-out is retained.

## 41. Go Files Expected to Change

```text
C:\xampp\htdocs\cosmiclink-phase6i\network-engine\internal\network\types.go
C:\xampp\htdocs\cosmiclink-phase6i\network-engine\internal\api\server.go
C:\xampp\htdocs\cosmiclink-phase6i\network-engine\internal\api\server_test.go
C:\xampp\htdocs\cosmiclink-phase6i\network-engine\internal\config\config.go
C:\xampp\htdocs\cosmiclink-phase6i\network-engine\internal\provider\fake.go
C:\xampp\htdocs\cosmiclink-phase6i\network-engine\internal\provider\mutation_provider.go       (new)
C:\xampp\htdocs\cosmiclink-phase6i\network-engine\internal\provider\routeros_operations.go       (new)
C:\xampp\htdocs\cosmiclink-phase6i\network-engine\internal\provider\routeros_operations_test.go (new)
C:\xampp\htdocs\cosmiclink-phase6i\network-engine\cmd\server\main.go
```

Do not add writes to discovery/monitoring providers.

`network.Request` has no credential field today and `internal\api\server.go` decodes mutation requests with `DisallowUnknownFields()`, so the Laravel client and the Go request struct must change in the same deployable unit. Reuse the existing `network.DiscoveryConnection` shape rather than inventing a second connection struct. Do **not** widen `provider.RouterOSTransport`: its `Connect/Read/Close` contract is read-only by design, is documented as preventing arbitrary RouterOS sentences, and is imported by `internal\monitoring` (its own `monitoringTransport`) — add a separate `RouterOSMutationTransport` so the read-only interface and its two existing implementations stay untouched. No new Go dependency is required: `go.mod` already pins `github.com/go-routeros/routeros/v3 v3.0.1` (module `cosmiclink/network-engine`, go 1.22).

## 42. Vue/UI Files Expected to Change

The audited Phase 6H UI is Blade-based; no Vue controlled-operation surface was found. Expected files are:

```text
C:\xampp\htdocs\cosmiclink-phase6i\resources\views\network\accounts.blade.php
C:\xampp\htdocs\cosmiclink-phase6i\resources\views\network\discovery.blade.php
C:\xampp\htdocs\cosmiclink-phase6i\resources\views\customers\show.blade.php
C:\xampp\htdocs\cosmiclink-phase6i\resources\views\network\logs.blade.php
```

Do not re-theme or introduce Vue unless a later approved UI architecture requires it.

## 43. Focused Laravel Tests

Create `tests\Feature\Phase6IControlledNetworkOperationsTest.php` (the baseline feature suite ends at `Phase6HReconciliationAdoptionTest.php`). Cover:

- automatic MANAGED transition impossible;
- explicit MANAGED enablement and revocation;
- policy, tenant, connection/account/router/resource relationship;
- operator role requirement: a `customer`-role tenant user must be denied every controlled operation even though `RouterPolicy::operate()` would pass it (Section 10 gap);
- DISCOVERED/ADOPTED cannot write;
- kill switch off blocks MANAGED;
- reconciliation `NEW`, `MISSING`, `CHANGED`, `CONFLICT` blocks;
- stale discovery and unhealthy/unknown/stale/missing router-health evidence blocks;
- stable identity/preflight mismatch blocks;
- unknown-password account can only use three approved operations;
- unsupported operation/raw command impossible;
- duplicate idempotency and key conflict;
- concurrency and unknown outcome;
- forwarded (not re-derived) idempotency key and router credential present in the Go request payload;
- safe log evidence and secret sanitization;
- real provider never falls back to fake;
- overdue billing and payment create zero real mutations (assert persisted `network_accounts.status`, not only driver calls);
- simulation/real separation;
- tenant-scoped log visibility.

`phpunit.xml` pins the testing environment to PostgreSQL `cosmiclink_test` with `CACHE_STORE=redis` and `SESSION_DRIVER=array`, so feature tests need Postgres and Redis running and cannot rely on SQLite in-memory. Run the narrow slice first (`php artisan test --filter=Phase6I`), then `tests/Unit/GoNetworkDriverTest.php`, `FakeNetworkDriverTest.php`, and the Phase 6B/6C/6G/6H suites that assert the current Go request shape.

## 44. Go Tests

Cover:

- exact allowlist and route/operation pairing;
- no raw command/path endpoint;
- RouterOS v6 request construction;
- enable/disable preflight, write, postflight;
- disconnect session selection/removal;
- already enabled/disabled/no session no-ops;
- account not found, auth failure, timeout, cancellation, protocol error;
- postflight mismatch and unknown outcome;
- idempotency replay/conflict;
- secret/token redaction;
- fake mutation counter;
- mutation provider cannot call discovery/monitoring arbitrary paths;
- mutation provider selection: unknown provider name is a configuration error (no silent fake fallback), and the default resolves to fake;
- the mutation transport interface cannot issue any sentence outside the allowlist, and the read-only `RouterOSTransport` still exposes no write method;
- mutation kill switch off;
- unsupported operation rejection.

No Go test may connect to the production MikroTik. Keep the existing `internal\api\server_test.go`, `internal\api\monitoring_test.go`, and `internal\provider` test call sites compiling by adding a constructor (for example `NewWithMutationProvider`) instead of changing the arity of `api.NewWithMonitoring(provider, discovery, monitor, token, logger)`, which every current test already calls.

## 45. Regression Tests

Run the existing Phase 2, provisioning, Phase 6B, 6C, 6D, 6G, 6H, tenant-isolation, operation-log, discovery, monitoring, and FakeNetworkDriver suites. Change assertions only where Phase 6I intentionally isolates billing from real writes; do not weaken Phase 6H's zero-write guarantees.

## 46. Fake E2E

Use fake discovery/provider state to discover, adopt, enable MANAGED locally, execute each approved operation, assert logs/evidence/idempotency, revoke MANAGED, and assert future rejection. This must use zero RouterOS sockets and zero real mutation calls.

## 47. Future Real Hardware Acceptance

This audit performs no hardware acceptance. Future acceptance must use a dedicated safe PPPoE test account manually created by the operator outside CosmicLink, or explicitly record `real mutation acceptance: NOT EXERCISED`. CosmicLink must not create the test account. The operator must identify the account, recovery path, maintenance window, RouterOS version, and least-privilege RouterOS user scope before testing.

## 48. Expected-Delta Fingerprint Strategy

Capture secret-free pre/post fingerprints for router identity/version, target secret `.id`/name/profile/disabled, target active sessions, and unrelated resource identities/counts. Accept only:

```text
DISABLE: target disabled false -> true
ENABLE:  target disabled true -> false
DISCONNECT: target active session id disappears
```

Any unrelated account/profile/session/router/resource change fails and stops acceptance. Do not require whole-router equality when one target field is intentionally changed.

## 49. Security Review

Before implementation approval verify: no caller-controlled RouterOS command; no caller-controlled tenant/provider/driver; separate management and execution permissions; safe-default kill switches; explicit fake/real selection; Go authentication; API-SSL preference; no insecure TLS outside local/testing; least-privilege RouterOS user reviewed out-of-band; recursive redaction; append-only logs; unknown outcomes block writes; billing cannot reach real control; circuit-breaker extension points exist.

## 50. Rollback Strategy

Revoke MANAGED to stop future writes without changing RouterOS. Disable the global mutation switch to stop all real operations. If the provider is unsafe, stop the Go mutation endpoint/process while retaining read-only discovery/monitoring. Preserve evidence and unknown outcomes. Never run mass compensating operations during rollback.

## 51. Non-Goals

No real billing enforcement, Smart Auto-Isolation, mass suspension, automatic payment reactivation against RouterOS, PPPoE create/delete, password/profile/queue management, firewall/NAT/routes/VLAN/DHCP/DNS, RouterOS users/services, reboot/scripts/scheduler/import/restore, topology/PostGIS, OLT, ticketing, technician workflow, WhatsApp production, QRIS, AI/Copilot, or arbitrary RouterOS commands.

## 52. Remaining Risks

1. Go idempotency is currently in-memory and not restart durable.
2. RouterOS `.id` behavior must be validated against RouterOS 6.49.13 before real acceptance; always re-resolve it during preflight.
3. Existing RouterOS user permissions still need a separate least-privilege review; do not change them during this audit.
4. Multiple active sessions need an explicit policy; MVP blocks ambiguous multiple-session disconnect.
5. Health/freshness windows require safe operational tuning.
6. Legacy accounts need a migration state that cannot become MANAGED accidentally.
7. Billing currently calls the shared service directly; both billing and service gates are required.
8. A network timeout can remain uncertain even after postflight; manual review is intentional.
9. Exact RouterOS v6 sentence behavior must be validated against documentation and a safe test target before hardware use.

## 53. Final Implementation Verification Gates

Implementation is not ready for real hardware until focused Laravel tests, Go tests, full available regression tests, static checks, allowlist/raw-command searches, billing isolation, kill-switch, unknown-outcome, tenant/policy, redaction, and fake E2E checks pass. Real acceptance must either use the manually designated safe account with expected-delta fingerprints or be explicitly marked not exercised.

## Mandatory Architecture Answers

### Question 1 — What exactly does MANAGED authorize?

For one tenant-owned, adopted account/router, only explicit manual `ENABLE_PPPOE`, `DISABLE_PPPOE`, and `DISCONNECT_SESSION`, subject to every gate in this plan. It authorizes no other RouterOS capability.

### Question 2 — Can `encrypted_secret = NULL` accounts become MANAGED?

Yes, for those three operations. No password is extracted or required. Creation, password changes, and synchronization remain forbidden.

### Question 3 — What is the exact first RouterOS write allowlist?

Only `/ppp/secret/set` for `disabled=yes|no` on a preflight-resolved `.id`, and `/ppp/active/remove` for one preflight-resolved active-session `.id`. Required reads are the four hardcoded print commands listed in Section 12. Everything else is forbidden.

### Question 4 — How is Phase 2 billing prevented from automatic real writes?

The current paths are `EnforceBillingCommand -> ProcessOverdueBilling -> SuspendCustomerConnection -> NetworkOperationService::changeStatus` and `RecordPayment -> ReactivateCustomerConnection -> changeStatus`. Phase 6I blocks real controlled calls in billing actions and again in the shared service, with tests proving zero real requests.

### Question 5 — What happens after a possible post-write timeout?

Record `UNKNOWN_OUTCOME`, do not retry or compensate, perform a safe postflight/reconciliation read, close only if expected state is proven, otherwise block and require manual review.

### Question 6 — What is the global emergency kill switch?

`NETWORK_MUTATIONS_ENABLED=false` by default, enforced in Laravel and mirrored safely in Go. It blocks every real mutation, including MANAGED accounts.

### Question 7 — What exact conditions must all be true?

```text
REAL_MUTATION_ALLOWED =
    laravel_mutations_enabled
    AND go_mutations_enabled
    AND authenticated_authorized_operator
    AND tenant_matches_all_models
    AND account_state == MANAGED
    AND adopted_evidence_matches_account_connection_router
    AND reconciliation == MATCHED
    AND discovery_fresh
    AND router_health == ONLINE and fresh
    AND supported_router_identity/version
    AND operation_exactly_allowlisted
    AND no_unknown_or_concurrent_operation
    AND idempotency_reservation_valid
    AND preflight_identity_matches
    AND provider_preconditions_pass
```

### Question 8 — How is MANAGED revoked safely?

Lock local rows, set MANAGED back to ADOPTED, clear managed actor/time, record revocation, preserve relationship/history, and make zero RouterOS calls.

### Question 9 — Does Phase 6I need a migration?

Yes for implementation: account management state, managed/revoked attribution, durable idempotency, account linkage, provider/mode, evidence, unknown status, and resolution are not safely represented by existing metadata/log fields. No migration is created in this audit.

### Question 10 — How will future hardware acceptance prove only the expected target changed?

Use a manually designated safe test account, capture secret-free pre/post fingerprints, execute one approved operation, accept only the documented target delta, and fail/stop on any unrelated change. Without a safe test account, mark acceptance NOT EXERCISED.

## Audit Safety Declaration

```text
MIKROTIK WRITE OPERATIONS DURING AUDIT: 0
MIKROTIK CONFIGURATION CHANGED: NO
MANAGED TRANSITIONS EXECUTED: 0
ROUTEROS PERMISSIONS CHANGED: NO
SOURCE IMPLEMENTATION CHANGES: 0
```

Only this planning document may be added.

## 54. Baseline Verification Addendum (read-only)

Every assumption this plan makes about existing code was re-read from disk in this session rather than carried over from earlier notes. Method: `git status`/`git log` inspection plus full reads of the Laravel network and monitoring service layer, models, policies, controllers, config, migrations, and test harness, and of the Go engine (`cmd`, `internal/api`, `internal/config`, `internal/network`, `internal/provider`, `internal/monitoring`). No production file was created, edited, deleted, or executed against hardware; `git status --porcelain` still lists only this planning document.

### 54.1 Confirmed facts with evidence

| # | Verified baseline fact | Evidence | Plan impact |
|---|---|---|---|
| 1 | The Go mutation provider is hard-wired to the fake implementation; every authenticated mutation route runs `FakeProvider` regardless of Laravel's `NETWORK_DRIVER=go`, and startup logs `"provider","fake"` unconditionally | `network-engine\cmd\server\main.go:39`, `:45` | Phase 6I creates the first real write path; today zero RouterOS writes are architecturally reachable. Strongest evidence the 6I gate is net-new, not a relaxation |
| 2 | Engine config has only: `GO_NETWORK_ENGINE_ADDRESS`, `GO_NETWORK_ENGINE_TOKEN`, `NETWORK_DISCOVERY_PROVIDER`, `MONITORING_PROVIDER`, and `APP_ENV` + `GO_NETWORK_ENGINE_ALLOW_INSECURE_ROUTEROS_TLS`. There is no mutation-provider key | `network-engine\internal\config\config.go:9-30` | `config.go` must gain the mutation provider and kill switch; added to Section 41 |
| 3 | Fail-closed provider selection is existing precedent: unknown discovery/monitoring names return an error and `main.go` exits 1; `NewDiscoveryProvider` has no fake fallback for a typo | `internal\provider\discovery_provider.go:9-18`, `cmd\server\main.go:22-36` | Section 18's "never fall back to fake" should mirror this factory shape in a new `mutation_provider.go` |
| 4 | The RouterOS transport abstraction is deliberately read-only (`Connect`/`Read`/`Close`), guarded by a fixed print allowlist and `ErrForbiddenRouterOSCommand`, and the monitoring package imports it for its own transport | `internal\provider\routeros_discovery.go:17-39`, `internal\monitoring\routeros.go:14-31` | The mutation transport must be a new interface; widening the shared one would expand the read-only surface (Section 41 note) |
| 5 | RouterOS admin credentials already travel Laravel to Go per request on read paths, taken from `Router::password()` (`routers.encrypted_credentials`). `NetworkAccount::secret()` is the customer PPPoE secret and is unrelated | `app\Models\Router.php:19-27`, `app\Services\Network\GoNetworkDiscoveryClient.php`, `GoNetworkMonitoringClient.php` | Confirms Sections 12/23: an unknown PPPoE password cannot block MANAGED, and writes reuse the same inline, never-persisted credential pattern |
| 6 | `network.Request` carries no credential field and mutation decoding is strict (`DisallowUnknownFields`) | `internal\network\types.go`, `internal\api\server.go` | Laravel and Go must change in one deployable unit |
| 7 | The driver interface accepts no idempotency key, acting user, or execution mode; `GoNetworkDriver` derives `idempotency_key` as `sha256(operation, tenant, router, account, params)` and mints a fresh `operation_id` UUID per call | `app\Services\Network\NetworkDriver.php:9-19`, `GoNetworkDriver.php:47-60` | Section 36's durable client key is a real contract change; `tests\Unit\GoNetworkDriverTest.php:40-41` asserts the current fields |
| 8 | The Laravel to Go mutation client performs **no** automatic retry; connection failures collapse to `NETWORK_ENGINE_TIMEOUT`/`NETWORK_ENGINE_UNAVAILABLE`, malformed bodies to `NETWORK_ENGINE_INVALID_RESPONSE` | `GoNetworkDriver.php:62-80` | Section 28 already holds; 6I preserves it and adds an explicit unknown-outcome state |
| 9 | The operation log is written **after** the driver call, outside a transaction, with `target` = username and status only `success`/`failed` | `app\Services\Network\NetworkOperationService.php:54-76` | Reservation-before-dispatch, account linkage, mode, and `unknown` are genuinely new (Section 39) |


| 10 | Router health evidence is `health_observations` rows with `subject_type='router'` read via `Router::networkHealth()` (`latestOfMany('observed_at')`); no router-health table exists | `app\Models\Router.php:44-52`, `app\Models\HealthObservation.php` | Section 22 must name this source |
| 11 | `MonitoringService::persist()` skips inserting a new row while the state is unchanged inside `checkpoint_seconds` (default 300), yet `freshness_seconds` defaults to 180 | `app\Services\Monitoring\MonitoringService.php:58-66`, `config\monitoring.php:6-9` | A persisted `ONLINE` row can legitimately be older than the freshness window, so a naive "fresh within 180s" gate would deny valid mutations. The gate needs its own explicit window and/or a forced read-only re-observe immediately before dispatch |
| 12 | The existing discovery-freshness rule is a hard-coded 24h check on `DiscoveredNetworkResource.last_seen_at` returning 409; no `fresh_minutes` or `NETWORK_DISCOVERY_FRESH_MINUTES` key exists anywhere in `app`, `config`, `routes`, or `database` | `app\Services\Network\AdoptDiscoveredNetworkResource.php:30` (empty `git grep` for `FRESH_MINUTES`) | Section 21's "fresh discovery" requires a named config key and a stated source, and should not be stricter than the window that permitted adoption |
| 13 | `config('monitoring.simulation')` already models simulated versus real from driver plus `APP_ENV` | `config\monitoring.php:5` | Section 32's SIMULATION/REAL mode should follow this precedent in `config\network.php` |
| 14 | `RouterPolicy::operate()` delegates to `view()`, which is a pure `tenant_id` equality test, so **tenancy is the only authorization factor in the application**; `User.role` is an inert plain `string` column (`default 'admin'`, no enum/CHECK, not in `User::$fillable`) that is read **nowhere** in `app`, `config`, `routes`, or `tests`, and whose only two written values are `admin` (factory) and `owner` (seeder) — there is no `manager` or `customer` value anywhere in the repository | `app\Policies\RouterPolicy.php:10-28`, `app\Models\User.php:21-26`, `database\migrations\2026_09_16_000002_add_tenant_and_role_to_users_table.php:13`, `database\factories\UserFactory.php:34`, `database\seeders\DatabaseSeeder.php:30`, plus a case-insensitive `git grep -i role -- app config routes tests` that returns no matches | Section 10 does not reuse an existing role mechanism, it **creates** the first one; the earlier `admin\|manager\|customer` claim in this row was wrong and is corrected by the full audit in Section 55 |
| 15 | Legacy write routes are live today: `network.accounts` store/enable/disable/profile/disconnect plus a `{account}/{status}` route, guarded only by tenancy and the adopted-row 422 | `routes\web.php:70-75`, `NetworkAccountController.php:56-64` | Phase 6I must convert these call sites into the controlled path rather than adding a parallel endpoint set |
| 16 | Device identity and version come from the latest successful snapshot's `snapshot['device']` (`name`, `routeros_version`, `architecture`, `board_name`, `platform`) | `app\Http\Controllers\NetworkDiscoveryController.php:25-32` | Section 8's supported-version precondition should read this evidence, not a new router column |
| 17 | The test environment is PostgreSQL `cosmiclink_test` with Redis cache and array sessions; feature suites end at `Phase6HReconciliationAdoptionTest.php` | `phpunit.xml:20-42`, `tests\Feature`, `tests\Unit` | Section 43 filename and service prerequisites pinned |
| 18 | `go.mod` is module `cosmiclink/network-engine`, go 1.22, requiring only `github.com/go-routeros/routeros/v3 v3.0.1` | `network-engine\go.mod:1-5` | No dependency or vendor-surface growth for the mutation provider |
| 19 | Zero-write evidence already exists: `GET /v1/discovery/safety/mutation-count` behind bearer auth plus `FakeProvider.MutationCount()` | `internal\api\server.go`, `internal\provider\fake.go` | Re-prove "RouterOS writes 0" post-implementation through this endpoint and the counter |
| 20 | Views are Blade only (33 files) including `network\accounts`, `network\discovery`, `network\logs`, `network\_logs`, `routers\show`, `customers\show` | `resources\views` | Section 42 is accurate; add `network\_logs.blade.php` and `routers\show.blade.php` as affordance candidates |

### 54.2 Environment matrix (authoritative names)

| Knob | Process | Values | Baseline |
|---|---|---|---|
| `NETWORK_DRIVER` | Laravel | `fake` \| `go` | `fake` (`config\network.php`, `.env.example:8`) |
| `NETWORK_DISCOVERY_PROVIDER` | Laravel **and** separately the Go process | `fake` \| `routeros` | `fake` (`config\network.php:10`, `internal\config\config.go:21`) |
| `MONITORING_DRIVER` | Laravel | `fake` \| **`engine`** | `fake` (`config\monitoring.php:4`, `AppServiceProvider.php:62-68`) |
| `MONITORING_PROVIDER` | Go | `fake` \| `routeros` | `fake` (`internal\config\config.go:22`) |
| `NETWORK_MUTATIONS_ENABLED` | Laravel **and** Go (proposed) | bool, default false | absent today |
| `NETWORK_MUTATION_PROVIDER` | Go (proposed) | `fake` \| `routeros` | absent today |

Consequences: `NETWORK_DISCOVERY_PROVIDER` is consumed by two different processes, so the new mutation switch needs its own distinct name configured explicitly in both; and the Laravel monitoring value is `engine`, not `go`, so any real-mode runbook line must read `MONITORING_DRIVER=engine`.



### 54.3 Corrections to assumptions carried in from earlier sessions

- The Laravel `GoNetworkDriver` was assumed to blind-retry mutations on connection errors. Direct reading disproves it: there is no `Http::retry` in the file (fact 8). Section 28 is therefore a preservation requirement, not a fix.
- `tests\TestCase.php` was assumed to carry an `ALL_DISCOVERED` count marker. It does not; it is the stock Laravel base class and `git grep ALL_DISCOVERED` is empty. Verification must instead use real evidence: `php artisan test` output, `go test ./...`, `FakeProvider.MutationCount()`, and the safety `mutation-count` endpoint. Where this plan says "audit marker" it means a recorded verification statement, not a code constant.
- A `network_router_health` table, a `NETWORK_DISCOVERY_FRESH_MINUTES` knob, and an `AccountManagementService` class were assumed to exist. None do; the real sources are facts 10, 12, and the `NetworkAccountController` + `NetworkOperationService` pair.

### 54.4 Verification commands for implementation (no hardware)

```text
cd network-engine && go vet ./... && go test ./... && go build ./...
php artisan config:clear && php artisan test --filter=Phase6I
php artisan test --filter=GoNetworkDriver
php artisan test          # full suite, requires Postgres cosmiclink_test and Redis
```

Expected unchanged outcome: `NETWORK_MUTATIONS_ENABLED` unset means false; `NETWORK_DRIVER=fake` and/or a `fake` Go mutation provider keep RouterOS writes at zero; discovery and monitoring routes keep their read-only allowlists.

Measured at this audit, read-only with no hardware: `go vet ./...` exits 0 and `go test ./...` exits 0 (`internal/agent`, `internal/api`, `internal/monitoring`, `internal/provider` pass; `cmd/agent`, `cmd/server`, `internal/config`, `internal/network` have no test files). Re-run this after implementation as the Go regression gate.

### 54.5 Implementation order once approved

1. Additive migration plus model/gate scaffolding, tests first, deny by default.
2. Go `mutation_provider.go` and `routeros_operations.go` with tests, keeping `FakeProvider` the default and the read-only transport untouched.
3. Laravel and Go request-contract change in one commit (key forwarding, credential field, strict decode).
4. Real mode behind both kill switches, then Blade affordances, then full-suite regression and zero-write evidence.

No step above is authorized by this audit. Implementation begins only after explicit approval of this plan.
## 55. Authentication, Authorization, and Tenancy Audit (read-only)

Performed from source before any implementation, because Sections 10, 32, and 36 describe authorization rules. This section records what the application actually does today, so that Phase 6I neither assumes a role system exists nor pretends one is absent.

### 55.1 Authentication surface

| Verified fact | Evidence |
|---|---|
| Exactly one guard: `web` (session driver) on the `users` provider, model `App\Models\User`; no token guard, no Sanctum, no Passport | `config\auth.php:18-21`, `40-45`, `64-68` |
| Login is `Auth::attempt(['email','password'])` + `session()->regenerate()` + `redirect()->intended('/dashboard')`; logout invalidates the session and regenerates the token. No 2FA, no password confirmation, no session/device management | `app\Http\Controllers\AuthController.php:15-33` |
| No login throttling exists: no `throttle` middleware and no `RateLimiter::for` anywhere in `app`, `routes`, `bootstrap`, or `config`; the `passwords.users.throttle` value is dead config because there is no password-reset route or controller | `git grep -n "throttle\|RateLimiter" -- app routes bootstrap config` matches only `config\auth.php:100`; `routes\web.php:22-25` |
| The only middleware registered by the application is the `auth` alias (pointing at the framework's own `Authenticate`); `app\Http\Middleware` does not exist, so there is no role, tenancy, or onboarding middleware | `bootstrap\app.php:15-17`, `Test-Path app\Http\Middleware` is `False` |
| One `Route::middleware('auth')` group protects every web surface; the JSON API reuses `['web','auth']`, so it authenticates with the same browser session and inherits the `web` group's CSRF posture | `routes\web.php:27`, `routes\api.php:7` |
| The machine plane is separate and is not a user path: `/api/v1/agent/*` carries no session middleware and authenticates a bearer `token_id.secret` via `token_id` lookup plus `Hash::check`, with a legacy scan for `token_id IS NULL`; each mutation then re-checks tenant match, job ownership, `attempt`, and a `hash_equals` fence token | `routes\api.php:23-28`, `app\Http\Controllers\AgentApiController.php:46-52`, `app\Services\Network\NetworkAgentService.php:30-52`, `138-143`, `182-195` |
| Agents can only ever be handed `DISCOVER_ROUTER` work today; there is no agent-side mutation job type | `app\Services\Network\NetworkAgentService.php:138` |
| There is no user-administration surface of any kind (no `users` routes), so `role` and `tenant_id` are never set from an HTTP request | `routes\web.php`, `routes\api.php` |

Consequence for Phase 6I: an authenticated principal is always a human member of one tenant, and the agent plane must not be widened into a mutation entry point without its own authorization story (fence, job type, and tenant checks already give it one).

### 55.2 Authorization is tenancy and nothing else

`AppServiceProvider::boot()` registers eight policies and **no `Gate::before`**, so there is no superuser bypass and no global role rule. Each ability in each policy reduces to the same expression, `$user->tenant_id === $model->tenant_id`:

| Policy | Abilities | Body, in effect |
|---|---|---|
| `RouterPolicy` | `view`, `update`, `delete`, `operate` | `view` = tenant equality; `update`, `delete`, `operate` each `return $this->view(...)` |
| `CustomerPolicy` | `view`, `update`, `delete` | same |
| `InternetPackagePolicy` | `view`, `update` | same |
| `CustomerConnectionPolicy` | `view`, `update` | same |
| `InvoicePolicy` | `view`, `update` | same |
| `PaymentPolicy` | `view`, `create` | same |
| `PaymentRequestPolicy` | `view` | tenant equality only |
| `OutageIncidentPolicy` | `view`, `acknowledge` | `acknowledge` = `view()` **and** `$incident->status === 'detected'` — a lifecycle precondition, not a privilege |

Evidence: all eight files under `app\Policies\` read in full; `app\Providers\AppServiceProvider.php:71-81`.

Models with no policy class at all — `NetworkAccount`, `DiscoveredNetworkResource`, `NetworkOperationLog`, `HealthObservation`, `NetworkAgent`, `NetworkAgentJob` — are protected instead by inline tenancy checks or `where('tenant_id', ...)` scoping, for example `NetworkAccountController.php:59-63`, `AdoptDiscoveredNetworkResource.php:16` and `:56`, and `OperationLogController::index`. Tenancy only, again.

Therefore the application has **object-level tenancy authorization and zero functional authorization**. There is no way today to express "this user may write to a router but may not edit a customer", and the same is true in reverse: every authenticated user of a tenant can already create, enable, disable, re-profile and disconnect PPPoE accounts, run discovery, adopt and unadopt resources, edit and delete routers, generate invoices, record payments, and suspend or reactivate connections.

### 55.3 Tenancy enforcement is consistent and fail-closed

- The authoritative tenant is always `Auth::user()->tenant_id` — 44 occurrences across 14 controllers — and **no** controller or action reads `tenant_id` from request input (`git grep` for request-sourced `tenant_id` returns no matches).
- The service and action layer repeats the check rather than trusting the caller: `ProvisionCustomerConnection.php:18`, `RecordPayment.php:16`, `ProcessPaymentProviderEvent.php:18`, `SendCustomerMessage.php:20`, `SuspendCustomerConnection.php:17`, `GenerateInvoiceForConnection.php:17`, `MonitoringService.php:18-32`, `OutageCorrelationService.php:26`, `AdoptDiscoveredNetworkResource.php:16-56`, `NetworkAgentService.php:138` and `:194`.
- `users.tenant_id` is nullable, and because every comparison is strict (`===`), a user with a null tenant matches nothing and is denied. Phase 6I must preserve this: no `==`, no `??`-coalesced tenant ids, no "empty means unrestricted".
- Laravel to Go passes `tenant_ref` and `router_ref` for correlation only; the engine shares no session or cookie with Laravel, so tenancy can never be delegated to it (`GoNetworkDriver.php:52-60`).

### 55.4 How tenancy is actually enforced, and where it is fragile

- There is **no global tenant scope**: `git grep "addGlobalScope" -- app` returns nothing, and the only `booted()` methods in `app\Models` are code generators for `customer_code`, `connection_code`, and `invoice_number` (`Customer.php:14-18`, `CustomerConnection.php:16-20`, `Invoice.php:16-20`).
- Tenancy therefore rests entirely on two hand-written patterns: query-level `where('tenant_id', Auth::user()->tenant_id)` for lists, and `Gate::authorize(...)` / `abort_unless($model->tenant_id === Auth::user()->tenant_id, ...)` for bound singletons (`ApiController.php:41`, `:108`, `:118`, `:133`, `:140`; `NetworkAccountController.php:61`; `NetworkAgentController.php:33`).
- The status code for a cross-tenant hit is inconsistent: `NetworkAgentController::show` returns **404**, `NetworkAccountController::authorizeAccount` returns **403**, `AdoptDiscoveredNetworkResource` returns **403**. Phase 6I must pick one and say why; a mutation endpoint that reveals existence through a 403/404 split is an information leak across tenants.
- Route-model binding is used for singletons, which means a bound `Router`, `NetworkAccount`, or `CustomerConnection` reaches controller code **before** any tenancy proof exists. The tenancy proof is only as reliable as the developer remembering to add it per action. For 6I this is the decisive constraint: a new mutating route must not rely on remembering a per-controller check.
- Two pre-existing non-tenancy guards are state preconditions that behave like authorization and must be respected rather than bypassed: adopted-account protection (`NetworkAccountController.php:62` → 422 "Adopted accounts are read-only."; `SuspendCustomerConnection.php:17`, `ReactivateCustomerConnection.php:16`), and discovery evidence freshness (`AdoptDiscoveredNetworkResource.php:29-30` → 422 on state, 409 on stale `last_seen_at > 24h`).

### 55.5 The `role` column is inert — there is no role system to reuse

| Verified fact | Evidence |
|---|---|
| `users.role` is `string` with default `admin`, indexed alongside `tenant_id`; there is **no enum, no CHECK constraint, no allowlist** anywhere | `database\migrations\2026_09_16_000002_add_tenant_and_role_to_users_table.php:13-15` |
| Nothing in the PHP application, config, routes, front-end, or tests ever **reads** `role` — `git grep -n role -- app` returns zero matches | verified across `app`, `config`, `routes`, `resources`, `tests` |
| Only two values are ever written: `owner` by the seeder and `admin` by the default factory state; `manager` and `customer` do not exist anywhere in the repository | `database\seeders\DatabaseSeeder.php:30`, `database\factories\UserFactory.php:34` |
| `role` is **not** in `User::$fillable` (`['tenant_id','name','email','password']`), so `$user->update(['role' => 'x'])` silently does nothing | `app\Models\User.php:21-26` |
| Factories do bypass mass assignment, because Laravel instantiates models inside `Model::unguarded()`; so `User::factory()->create(['role' => 'x'])` works while an equivalent `update()` would not | `vendor\laravel\framework\src\Illuminate\Database\Eloquent\Factories\Factory.php:516` (`makeInstance`) |
| No permission package exists (no `spatie/laravel-permission` in `composer.json`), no `roles` table, no `role_has_permissions` table, and no `Gate::before` superuser rule | `composer.json`, `database\migrations`, `app\Providers\AppServiceProvider.php:71-81` |
| There is no user-administration route, so no HTTP path can change `role` today; the only way an operator user exists is by seeding or direct database edit | `routes\web.php`, `routes\api.php` |

This means Section 54.1 fact 14's earlier wording was wrong and has been corrected, and it changes what Section 10 means: 6I does not extend an authorization model, it introduces the **first** functional rule the application will ever evaluate beyond tenancy.

### 55.6 Decisions this audit forces on Section 10

1. **Vocabulary must be declared, not inherited.** Only `admin` and `owner` exist in any environment. The plan's operator set must therefore be exactly those two values (or a new value introduced deliberately), expressed as a config allowlist, e.g. `network.mutations.operator_roles` defaulting to `['owner','admin']`. Hard-coding `in_array($user->role, ['admin','owner'])` in several files is prohibited; one resolver, one source of truth.
2. **Unknown and null roles must deny.** Because there is no CHECK constraint, any string can already be in a production `role` column, and `null` is possible on rows inserted outside the schema default path. The rule is a positive allowlist, never a `!== 'customer'` exclusion, so a future non-staff role cannot inherit write rights by accident.
3. **`RouterPolicy::operate` cannot simply be tightened.** It is a shared ability also used by `network.accounts.store`, `monitoring.routers.observe`, and `monitoring.simulation.*` (`NetworkAccountController.php:23`, `MonitoringController.php:20`, `ApiController.php:108`). Changing `operate` to require an operator role would silently change the authorization of read and simulation surfaces documented in Sections 41 and 54.1 fact 15. The mutation gate must therefore add its own operator rule for writes, and Section 41's shared-allowlist warning applies unchanged.
4. **The gate must be a choke point, not a convention.** Section 55.4 shows tenancy today is remembered per action. Every 6I mutation must fail closed if the gate is not called: gate evaluation happens inside the single operation service entry point (Section 42), and the request object carries actor, tenant, capability, and operation. A controller that "forgets" the check must be unable to reach the driver.
5. **Cross-tenant response code must be fixed by the plan.** Recommended: `404` for "not in your tenant" on all new mutation endpoints, matching `NetworkAgentController::show` and avoiding existence disclosure, with `403` reserved for "in your tenant, but not permitted by role, capability, mode, or flag".
6. **Adopted-resource protection is inherited, not optional.** Any 6I write that can target a `NetworkAccount` must reproduce the adopted read-only rule (Section 55.4) or the new path becomes an escape hatch around an existing invariant.

### 55.7 Test-harness consequences (blocks the TDD step in Section 43)

- The default factory user is `role => 'admin'` with a tenant, so a test that only checks "an authenticated tenant user is denied" will pass for the wrong reason only when the allowlist excludes `admin`. Every authorization test must state both directions explicitly: an allowed role passes, a non-operator role is denied, and the deny reason is asserted (mode, flag, capability, or tenancy) rather than merely the status code.
- Because `role` is not mass-assignable, changing a user's role inside a test requires `User::factory()->create(['role' => 'x'])`, `$user->forceFill(['role' => 'x'])->save()`, or a named factory state. A plain `$user->update(['role' => 'x'])` is a silent no-op that would make a denial test pass vacuously. Add a `customerUser()` / `operatorUser()` state to `UserFactory` rather than scattering `forceFill` calls.
- `Router`, `CustomerConnection`, `Customer`, `Tenant`, `HealthObservation`, `NetworkOperationLog`, `OutageIncident`, `Invoice`, `Payment`, `PaymentRequest`, `MessageLog`, `InternetPackage` have factories; **`NetworkAccount` and `DiscoveredNetworkResource` do not**, so tests that need an account-level or resource-level mutation must build those rows by hand (or gain new factories declared in the plan).
- Tests must be written so that a null-tenant user and a null-role user are denied, locking in the fail-closed behaviour that Section 55.3 shows is currently only accidental.
- No existing test covers authorization denial at all (Section 54.1 fact 18: zero `403`/`404`/`assertForbidden` assertions), so every denial-path assertion in 6I is new coverage and must not be "borrowed" from a passing 200-only baseline.



## 56. Network-Operation Authorization Evidence Table and Prerequisite Record

Written after §55 and before any RouterOS work, because the Section 10 gate is meaningless while `User->role` is inert. This is the completed evidence mapping for every billing and network entry point, plus the record of the prerequisite fix that the audit forced.

### 56.1 Entry-point evidence table (state as found at branch tip `4a3041e`)

| # | Entry point | Route / caller | Authorization as found | Reaches a device? |
|---|---|---|---|---|
| 1 | `RouterController::test` | `POST /routers/{router}/test`, `routes/web.php:62` | `Gate::authorize('operate', $router)` (`RouterController.php:75`); `RouterPolicy::operate` = tenancy only (`RouterPolicy.php:25-28`) | yes, driver test |
| 2 | `NetworkAccountController::store` | `POST /network/accounts`, `:70` | tenancy only through `operate` (`NetworkAccountController.php:21-24`) | yes, `CREATE_PPPOE` |
| 3 | `NetworkAccountController::status` / `profile` / `disconnect` | `:71-75` | inline `abort_unless($account->tenant_id === Auth::user()->tenant_id, 403)` + adopted-account 422 (`:59-63`); no policy, no role | yes, `ENABLE/DISABLE/SET_PROFILE/DISCONNECT_SESSION` |
| 4 | `CustomerConnectionController::provision` | `POST /connections/{connection}/provision`, `:56` | `Gate::authorize('update', $connection)` → `CustomerConnectionPolicy` = tenancy only | yes, `CREATE_PPPOE` via `ProvisionCustomerConnection.php:36` |
| 5 | `NetworkDiscoveryController::discover` | `POST /network/discovery/routers/{router}`, `:67` | tenancy only through `operate` (`:50`) | yes, discovery client |
| 6 | `MonitoringController::check` | `POST /monitoring/check`, `:41` | **no authorization object at all** — session plus the actor's own `tenant_id` (`:35-40`) | yes, probes every router in tenant |
| 7 | `MonitoringController::observeRouter` / `observeConnection` | `:42-43` | `Gate::authorize('view', ...)` = tenancy only (`:44`, `:52`) | yes, single probe |
| 8 | `ApiController::check` | `POST /api/v1/monitoring/check`, `routes/api.php:15` | none beyond `['web','auth']` | yes |
| 9 | `BillingController::overdue` | `POST /billing/overdue`, `:32` | none beyond `auth`; `ProcessOverdueBilling` walks the tenant's overdue invoices | yes, via `SuspendCustomerConnection` |
| 10 | `PaymentController::store` → `RecordPayment` | `POST /billing/invoices/{invoice}/payments`, `:34` | `PaymentPolicy::create` = tenancy only | yes, via `ReactivateCustomerConnection` |
| 11 | `EnforceBillingCommand` | `php artisan billing:enforce` | console; actor is `$tenant->users->first()`, i.e. an arbitrary member that may hold the `customer` role | yes |
| 12 | Read surfaces: `routers.index/show`, `network.accounts.index`, `network.logs.index`, `monitoring.index`, `GET /api/v1/*` | — | tenancy only, deliberately unchanged | no |
| 13 | `monitoring.*.simulation`, `api.v1.monitoring.*.simulation` | `:44-45`, `api.php:16-17` | `update` / `view` plus `abort_unless(config('monitoring.simulation'), 403)`; writes only `health_observations` | no |
| 14 | `network.discovery.adopt` / `unadopt` | `:68-69` | tenancy plus local `management_state` bookkeeping | no |
| 15 | `/api/v1/agent/*` | `api.php:23-28` | bearer `token_id.secret`, then tenant, job-ownership, attempt and `hash_equals` fence checks | machine plane, `DISCOVER_ROUTER` only |
| 16 | user administration | — | none exists; `role` is absent from `User::$fillable` (`app/Models/User.php:21-26`), so role is never settable from HTTP | n/a |

Two corrections to §55, both from re-checking the code with `git grep`:

1. §55.6 item 3 claimed `operate` is shared with the simulation surfaces. It is not: `Gate::authorize('operate', ...)` exists at exactly three sites — `RouterController.php:75`, `NetworkAccountController.php:23`, `NetworkDiscoveryController.php:50`. The simulation endpoints authorize with `update`/`view` (`ApiController.php:108`, `MonitoringController.php:60`) and are additionally gated by `config('monitoring.simulation')`.
2. §55.7's last bullet claimed zero denial coverage. Tenant-level denial assertions do exist (`Phase1ProvisioningTest.php:38,42,55`, `Phase0FoundationTest.php:39`); what genuinely did not exist was any test that distinguishes **roles**, because role was never read.

### 56.2 Prerequisite fix — implemented (first executable task on this branch)

Rule, stated once: **a device operation requires the tenant boundary *and* a role listed in `config('network.operator_roles')` (default `owner,admin`). Missing, empty, unknown, or differently-cased roles fail closed.** Tenancy remains the data-isolation boundary and is unchanged everywhere.

| Change | File | Effect |
|---|---|---|
| `network.operator_roles` parsed from `NETWORK_OPERATOR_ROLES` (trim, dedupe, empties dropped) | `config/network.php` | allowlist is deployment-controlled; an empty value denies every role rather than allowing all |
| `User::isNetworkOperator(): bool` | `app/Models/User.php` | single capability resolver; `$this->role !== null && in_array((string) $this->role, $roles, true)` |
| `Gate::define('operate-network', ...)` | `app/Providers/AppServiceProvider.php` | one named, greppable capability; policies compose tenancy on top of it |
| `RouterPolicy::operate()` = `view()` **and** `operate-network` | `app/Policies/RouterPolicy.php` | tightens table 1, 2, 5 and, through reuse, 3, 4 |
| `authorizeAccount()` additionally `Gate::authorize('operate', $account->router)` | `app/Http/Controllers/NetworkAccountController.php` | closes table 3 (enable, disable, `{status}`, profile, disconnect) with no new policy class |
| `provision()` additionally `Gate::authorize('operate', $connection->router)` after the existing `update` check | `app/Http/Controllers/CustomerConnectionController.php` | closes table 4; connection tenancy still produces the first 403 for foreign rows |
| `check` / `observeRouter` / `observeConnection` require `operate-network` / `operate` | `app/Http/Controllers/MonitoringController.php` | closes tables 6, 7; a monitoring probe is device contact, so it follows the same rule |
| `ApiController::check` requires `operate-network` | `app/Http/Controllers/Api/V1/ApiController.php` | closes table 8; the web and API forms of one operation cannot diverge |
| `NETWORK_OPERATOR_ROLES=owner,admin` documented | `.env.example` | operators can narrow or widen without code |
| 13 new tests, 90 assertions | `tests/Feature/NetworkOperatorAuthorizationTest.php` | both directions for every gated surface, plus the invariants below |

No migration, no new policy class, no new route, no middleware, no change to `User::$fillable` (role stays unassignable from HTTP, so the fix cannot be self-escalated), and no change to any read or billing path. Existing 403 status semantics are preserved on all pre-existing surfaces; §55.6's recommendation to answer cross-tenant lookups with 404 applies to the **new** Phase 6I endpoints only, where the request contract is being defined anyway.

Tests pin, in addition to allow/deny: unknown, empty and case-mismatched roles fail closed; the allowlist is configuration-driven (with `['owner']`, `admin` is denied and `owner` succeeds); a cross-tenant operator is still denied and writes nothing; a non-operator keeps dashboard, customer, package, invoice, router-list, account-list, monitoring-list, operation-log and `api/v1` read access, and can still edit customer records; guests are still redirected (`web`) or answered `401` (JSON) before any role logic; a denial produces **zero** `network_operation_logs` rows, proving the check sits before dispatch; and with `network.driver=go` plus `Http::fake()`, a denied customer-role request sends **no** transport request at all (`Http::assertNothingSent()`), proving the rule is enforced before the engine is reached.

### 56.3 Deliberate exclusions and the residual gap this leaves

Billing-initiated device writes — table 9 (`POST /billing/overdue`), table 10 (verified payment → reactivate), table 11 (`php artisan billing:enforce`) — **remain tenancy-only**. Closing them here was ruled out because the prerequisite directive forbids billing-enforcement changes, and because the console path chooses its actor with `$tenant->users->first()`, which can legitimately resolve to a `customer`-role member; making the console honor the operator rule would alter enforcement behaviour, and making it skip such a tenant would silently stop suspensions. That is a behaviour change, not a hardening, and belongs to Phase 6I's own billing-isolation work (§34, §47 gate "billing must never reach real RouterOS writes").

Residual risk, stated plainly: today a `customer`-role member of a tenant can still cause a device write by clicking *Mark overdue* or by paying an invoice, and the nightly scheduler can do so with no human at all. The mitigation until that gap closes is entirely §11's kill switch plus §34's isolation, not role. Follow-ups recorded as tasks, not assumptions:

1. Phase 6I §34: assert in tests that billing paths cannot dispatch a real RouterOS write while the mutation switch is off, and decide whether billing actors need a service-account identity rather than an arbitrary tenant member.
2. Phase 6I §36: Blade has **zero** `@can` usage today (`git grep -n "@can" -- resources/views` is empty), so every network button is visible to every tenant member and fails with 403 on submit. Hide `routers.test`, account mutations, provisioning, discovery and monitoring actions behind `@can('operate', $router)` / `@can('operate-network')` in the same pass that adds the safety UI.
3. Out of 6I scope: a user-administration surface is required before roles are operable in production (there are no `users` routes, and `role` is not mass-assignable); roles are currently changed only by seeding or direct SQL.

### 56.4 Verification evidence

| Gate | Result |
|---|---|
| Red, before the fix | Branch HEAD code with the new file: **7 failed / 6 passed**; every failure was `Expected response status code [403] but received 302`, i.e. the customer-role user actually performed the device operation and got a success redirect. Captured with the implementation stashed (`git stash push -- app config/network.php .env.example`), then restored (`git stash pop`, stash list empty) |
| Green, focused | `php artisan test --filter=NetworkOperatorAuthorizationTest` → **13 passed (90 assertions)** |
| Regression, whole suite | `php artisan test` → **129 passed, 3 skipped, 0 failures (668 assertions)**; baseline without the new file was 116 passed, 3 skipped, so the fix broke no existing expectation and added 13 |
| Style | `vendor/bin/pint --test app config tests/Feature/NetworkOperatorAuthorizationTest.php` → **PASS, 113 files** |

The 3 skipped tests are pre-existing skips (`Phase6BGoNetworkEngineIntegrationTest`, `Phase6CLiveDiscoveryIntegrationTest`, `GoNetworkDriverTest`) — they require a live engine or hardware and are the reason §47's hardware acceptance stays deferred.

### 56.5 Two environment facts corrected against the resumed context

1. **No Phase 6I implementation exists on this branch.** `git log --oneline -3` shows the tip as `4a3041e CosmicLink Phase 6I plan controlled RouterOS operations`, and `git log --all --diff-filter=A` finds no commit anywhere that adds a RouterOS operation guard or Phase 6I tests. The resumed note that "Phase 6I implementation is complete, 16 files / 612 tests passed" is therefore not accurate: the suite on this branch contains 132 tests in 27 files, not 612. Tasks 1-16 of this plan remain to be implemented, and the prerequisite above is the only code change so far.
2. **The worktree had no dependencies, no `.env`, and no built assets.** Repaired locally, all inside gitignore: `composer install`, `.env` copied from `.env.example`, and `public/build` copied from the `master` checkout (the previous `vendor` junction was removed — a junction to another checkout makes Composer's `App\` autoload point at that other tree, which silently runs the wrong code; that is what produced the first misleading red run).

### 56.6 Next executable task

Task 1 (RouterOS operation allowlist / provider-registration migration groundwork) is now unblocked: the authorization boundary it must inherit is defined, tested, and consistent across every existing device-dispatching surface.

# PHASE 6I PLAN — READY

Summary: MANAGED is a narrow three-operation manual authorization; unknown PPPoE passwords are acceptable for that set; the global mutation switch is default-off; `MATCHED` fresh reconciliation and fresh `ONLINE` router health are mandatory; Phase 2 billing is isolated from real writes; idempotency and unknown outcomes fail closed; migration is YES for implementation; expected changes are centralized Laravel gate/service/policies/controllers/config, the existing Go API/provider boundary, and Blade-only safety UI; future hardware acceptance uses a manually designated test account and expected-delta fingerprints.

---

# TASK 1 IMPLEMENTATION EVIDENCE

## 57. Task 1 — safe RouterOS mutation provider boundary

Implemented on top of the verified authorization prerequisite. Task 1 is architecture only: it establishes the typed operation allowlist and the mutation-provider selection/registration boundary. It adds **no executable RouterOS write path** and performs **zero device I/O**.

### 57.1 Starting state

| Item | Value |
| --- | --- |
| HEAD before Task 1 | `b4c5894601e484a1576e92f814c4a1a4b0ad8b0f` — `Harden network operation authorization before Phase 6I` |
| Parent chain | `b4c5894` → `4a3041e` (plan) → `55fe60a` (Phase 6H) |
| Worktree before edits | clean except gitignored runtime files |
| Files changed by Task 1 | 15 paths: 4 Go files modified, 9 Go files added, plus the `.env.example` template entry and this plan document — **no PHP, Blade, Vue, migration, or Laravel route/config-file change** |

The five pre-Task 1 audit findings were re-confirmed against disk before editing, not from notes: `cmd/server/main.go` hard-wired `provider.NewFakeProvider()` into `api.NewWithMonitoring(...)`; `internal/config/config.go` had no mutation selection at all; `provider.RouterOSTransport` was `Connect/Read/Close` only; `internal/monitoring` imports that read-only shape as its own `monitoringTransport`; and `NETWORK_DRIVER=go` therefore still reached `FakeProvider` for every mutation.

### 57.2 Architecture actually implemented

```
Laravel GoNetworkDriver (unchanged)
  → POST /v1/network/...
    → api.Server
        legacy network.Provider    broad simulation: CREATE_PPPOE, CHANGE_PROFILE, TEST_CONNECTION
        network.MutationProvider   NEW narrow owner: ENABLE / DISABLE / DISCONNECT
      → provider.MutationProviderFor(selection, registered)   selection must agree with registration
        → FakeProvider (process-wide singleton)   …or…   hard startup refusal
```

| File | Role |
| --- | --- |
| `internal/network/mutation.go` (new, 53 lines) | `MutationOperation` string type, the three constants, `MutationProvider` interface, `MutationOperations()`, `MutationOperationFor()`, and `type MutationRequest = Request` (`network.MutationCounter` is reused from `types.go`, which Task 1 does not touch) |
| `internal/provider/mutation_provider.go` (new, 79 lines) | selection names, `ErrRealMutationProviderUnavailable`, `ErrUnsupportedMutationProvider`, `GlobalFakeProvider()`, `NewMutationProvider()`, `MutationProviderFor()`, `SupportedMutationProviders()` |
| `internal/provider/routeros_write.go` (new, 148 lines) | allowlist **data**, identity validation, `RouterOSPreparedWrite`, `PrepareRouterOSWrite()`, `RouterOSWritePathAllowed()`, `RouterOSMutationTransport` contract |
| `internal/api/server.go` (+119/−12) | mutation routes routed through the registered `MutationProvider`; `NewWithMutationProvider` composition; `writeCounter` reads the shared `MutationCounter` |
| `cmd/server/main.go` (+55/−16) | hard-wired `NewFakeProvider()` removed; testable `buildHandler` / `selectMutationProvider` / `monitoringProviderFor` |
| `internal/config/config.go` (+6) | `MutationProvider` field from `NETWORK_MUTATION_PROVIDER`, default `fake` |
| `internal/provider/fake.go` (+14) | `SupportedOperations()` plus compile-time assertions that `*FakeProvider` satisfies `Provider`, `MutationProvider`, `MutationCounter` |

`MutationRequest` is deliberately a **type alias** of `network.Request`, not a second wire type: the strict `DisallowUnknownFields()` decode remains the single Laravel→Go contract, and adding a credential field is a Task 4 change that §41 and §54.5 item 3 require to land in the same deployable unit as the Laravel client.

### 57.3 Exact mutation-provider selection behavior

| `NETWORK_MUTATION_PROVIDER` | Result |
| --- | --- |
| unset | `fake` — safe default, `valueOrDefault("NETWORK_MUTATION_PROVIDER", "fake")` |
| `fake` | shared `FakeProvider` instance, identical object used by the rest of the engine |
| `routeros` | `ErrRealMutationProviderUnavailable` → `main` logs `network engine startup refused` and `os.Exit(1)` |
| `""`, whitespace, `RouterOS`, ` routeros`, `routeros\n`, unknown name | `ErrUnsupportedMutationProvider` → startup refused |

The raw string is preserved rather than trimmed or lower-cased, so no casing or whitespace variant can coerce its way into a real selection. The two documented anti-patterns are structurally absent: `routeros → FakeProvider` requires an explicit fallback that no code path contains, and an invalid value can never widen to RouterOS because the default branch returns an error. Startup therefore exits non-zero rather than serving a degraded provider, exercised by `TestSelectMutationProviderRefusesTheRealRouterOSSHAt` and `TestBuildHandlerAcceptsTheFakeSelectionAndRejectsTheRealOne`.

### 57.4 Exact real-operation allowlist

`RealRouterOSOperations()` returns exactly three entries, matched case-sensitively with no trimming and no prefix parsing:

| Operation | Derived sentence (allowlist data only, never executed) |
| --- | --- |
| `ENABLE_PPPOE` | `/ppp/secret/set =.id=<identity> =disabled=no` |
| `DISABLE_PPPOE` | `/ppp/secret/set =.id=<identity> =disabled=yes` |
| `DISCONNECT_SESSION` | `/ppp/active/remove =.id=<identity>` |

Explicitly **not** real-write allowlisted, and rejected with `ErrUnsupportedMutationProvider` at the mutation boundary: `CREATE_PPPOE`, `CHANGE_PROFILE`, `SET_PASSWORD` / `RESET_PASSWORD` (no password operation exists in the type at all), `DELETE_PPPOE` / `DELETE_SECRET`, `REBOOT`, `SCHEDULE_SCRIPT`, `TEST_CONNECTION`, and raw command text (`/ppp/secret/set`, `/ppp/secret/print`, `PRINT`, `RAW`, `ARBITRARY`, `enable_pppoe`, `DISABLE_PPPOE;reboot`). The simulation deliberately exposes more than RouterOS may: `CREATE_PPPOE`, `CHANGE_PROFILE` and `TEST_CONNECTION` stay methods of the broad `network.Provider` interface (`CreateAccount` / `ChangeProfile` / `TestConnection`), while the narrow `network.MutationProvider` carries only `Name`, `SupportedOperations`, and the three approved writes — so `FakeProvider.SupportedOperations()` returns exactly `network.MutationOperations()` and can never widen real support.

Defense in depth at this layer: identities must match `\A(?:\*[0-9]{1,9}|[A-Za-z0-9][A-Za-z0-9._-]{0,31})\z`, the fixed sentence shape is assembled from a constant path plus `=.id=` plus a constant `=disabled=` token, a transport that accepts `RouterOSPreparedWrite` cannot be handed a caller-supplied string at all, `/ppp/secret/remove` is absent while `DISCONNECT_SESSION` is pinned to `/ppp/active/remove`, and error strings carry only static text so neither identity nor password can be reflected into a log.

### 57.5 Read-only boundaries held

| Boundary | Task 1 status |
| --- | --- |
| `provider.RouterOSTransport` (`Connect` / `Read` / `Close`) | **unchanged** — `git diff` for `provider/routeros_discovery.go`, `provider/discovery_provider.go`, `provider.go`, `monitoring/routeros.go` is empty. `TestReadOnlyRouterOSTransportContractStaysReadOnly` reflects over the interface and pins its method set to exactly `Close, Connect, Read`, failing if any name containing `Write`/`Set`/`Remove`/`Run`/`Execute` is ever added; it also asserts the production `realRouterOSTransport` still satisfies `RouterOSTransport` but does **not** satisfy `RouterOSMutationTransport` |
| `RouterOSDiscoveryProvider` | unchanged and still read-only; `TestDiscoveryAndMonitoringProvidersStayReadOnlyAfterTaskOne` type-asserts it satisfies `network.DiscoveryProvider` but never `network.MutationProvider`, confirms `allowedRouterOSReadCommands` is still the six print commands with neither write path present, and drives a real `Discover()` through a recording transport to prove `mutations == 0` |
| `monitoring.RouterOSProvider` | file unedited by Task 1; its own `monitoringTransport` alias remains read-only and stays outside the mutation boundary entirely |
| `go-routeros` library imports | still **only** `internal/monitoring/routeros.go` and `internal/provider/routeros_discovery.go` — no Task 1 file imports the client, and `routeros_write.go` pulls in only `context`, `errors`, `fmt`, `regexp`, `strings`, and `network` (no `net`, no dial, no client construction, no URL or port) |
| New write boundary | `RouterOSMutationTransport` (`Connect(ctx, DiscoveryConnection) error` / `Write(ctx, RouterOSPreparedWrite) error` / `Close() error`) — its method set is pinned to exactly `Close, Connect, Write` by `TestRouterOSMutationTransportIsASeparateBoundedContract`, and the only implementation in the tree is a call-counting test stub; **no production implementation exists** |
| Contract-shape gate | `TestRealRouterOSProviderShapeCannotSatisfyTheBroadContract` fixes `network.MutationProvider` at exactly 5 methods and asserts a narrow mutation provider does **not** satisfy the 7-method `network.Provider`, so it can never be plugged into the create / change-profile / test-connection routes; `FakeProvider` remains the only *production* type satisfying `network.MutationProvider` (test stubs excluded) |

Separation of concerns is explicit rather than implied: the mutation transport is a distinct interface from the discovery transport, and a leased read client is not handed to a writer — which is what makes `REAL ROUTEROS MUTATION PATH REACHABLE: NO` a checkable property rather than an assertion.

### 57.6 Actual verification results

Go, from `network-engine/` on a clean worktree after all edits:

| Gate | Result |
| --- | --- |
| `gofmt -l .` | empty — every file formatted |
| `go build ./...` | exit 0 |
| `go vet ./...` | exit 0, no output |
| `go test -count=1 ./...` | **exit 0** — 8 packages: 7 `ok`, and `cmd/agent` reports `[no test files]` (as it did at baseline) |
| `go test -count=1 -v` over the 5 affected packages | 53 top-level PASS, **0 SKIP**, 5/5 `ok` |
| New Task 1 tests | **35** across 6 files: 4 in `network/operations_test.go`, 7 in `provider/mutation_provider_test.go`, 9 in `provider/routeros_operations_test.go`, 7 in `api/mutation_registration_test.go`, 5 in `cmd/server/mutation_provider_selection_test.go`, 3 in `config/config_test.go` |
| `git diff --check` | exit 0 |

Laravel, from the Phase 6I checkout:

| Gate | Result |
| --- | --- |
| `php artisan test --filter="GoNetworkDriverTest\|Phase6BGoNetworkEngineIntegrationTest\|NetworkOperatorAuthorizationTest"` | **19 passed, 2 skipped** (116 assertions), 3.52 s; the 2 skips are the documented `localhost:8787` live-engine tests |
| `php artisan test` (full suite) | **129 passed, 3 skipped** (668 assertions), 15.24 s — identical pass/skip/assertion counts to the `b4c5894` baseline, so no regression |

Secret scan over the Task 1 diff: no credential or secret-shaped literal added; the only `password`-like strings live in negative tests that prove redaction (`/ppp/secret/set =name=alice =password=super-secret-value` plus its canary assertions). `.env.example` gained `NETWORK_MUTATION_PROVIDER=fake` with a comment block only — no RouterOS credentials, and the discovery/monitoring keys and their semantics are untouched. Two deliberate test residuals remain, both reviewed as safe: `cmd/server/mutation_provider_selection_test.go` defines a synthetic 32-hex constant (`compositionToken`) and `api/mutation_registration_test.go` a plaintext `"phase6i-test-token"`; neither is emitted or derived from a real secret, and they exist only to pass the real bearer middleware. Crucially, the composition test performs **no socket I/O at all** — `buildHandler` is driven through `httptest.NewRequest` + `handler.ServeHTTP`, so its `Address: "127.0.0.1:0"` field is inert config data that is never dialed, and `config_test.go`'s `127.0.0.1:8787` is only an assertion of the pre-existing loopback default. No Task 1 test can reach a device.

### 57.7 Safety declaration

```
REAL MIKROTIK WRITE OPERATIONS:        0
MIKROTIK CONFIGURATION CHANGED:        NO
ROUTEROS PERMISSIONS CHANGED:          NO
MANAGED TRANSITIONS:                   0
REAL ROUTEROS MUTATION PATH REACHABLE: NO
```

No connection to the hEX was attempted or possible. The local run environment resolves to `NETWORK_DRIVER=fake` with fake discovery and monitoring, and the Laravel tests that exercise the Go client use `Http::fake()`.

### 57.8 Corrections to earlier plan text

- §41 listed `internal/network/types.go` as the file expected to change for the operation vocabulary. **Actual:** `types.go` is untouched — the write vocabulary lives in a new `internal/network/mutation.go` and `network.Request` gains no field. Keeping the new type out of `types.go` also leaves the discovery/monitoring request shapes byte-identical, which §54.1 fact 6 depends on.
- §41 and §54.5 item 2 named the write file `internal/provider/routeros_operations.go`. **Actual:** `internal/provider/routeros_write.go` — one file holding the allowlist data, identity validation, `RouterOSPreparedWrite`, and the `RouterOSMutationTransport` contract. §41's substantive requirement (a *separate* mutation interface so that read-only `RouterOSTransport` and its two existing implementations stay untouched) is met; only the name and file count differ, and a separate transport file was not warranted for a three-method contract.
- §44 required keeping every existing `api.NewWithMonitoring` call site compiling by *adding* a constructor instead of changing its arity, while §41's file list expected `internal/api/server_test.go` to change. **Actual:** `api.NewWithMutationProvider` was added and `server_test.go` is byte-identical (`git status` lists no modification), satisfying the stricter of the two.
- §54.1 fact 1 recorded the hard-wiring at `cmd/server/main.go:39`, `:45`. **Actual:** that hard-wiring is gone, and the boundary is stronger than "replace the selection" — `MutationProviderFor` requires the config string and the registered provider to *agree*, so a provider registered as `routeros` while the config still says `fake` (or the reverse) is a startup refusal, and `NETWORK_MUTATION_PROVIDER=routeros` refuses rather than silently falling back to the fake, as the Global Constraints and §44 require. (§54.1 fact 3 attributes the "never fall back to fake" rule to Section 18; that rule actually sits in Global Constraints and §44 — §18 is Preflight Design. The historical section is left as written.)
- §54.2's environment matrix listed `NETWORK_MUTATION_PROVIDER` as `fake | routeros`. **Actual:** both named values behave as specified, and every value outside them — empty, whitespace, `RouterOS`, ` routeros`, `routeros\n`, any unknown name — is a configuration error rather than a coercion, which the two-value table left unstated.
- §54.5 item 3 anticipated the Laravel→Go request-contract change (credential field, key forwarding, strict decode) landing in one commit. **Actual:** Task 1 adds no credential field — the fake needs no device credential, and a field with no real caller would invite a half-done wire change. That item stays a Task 4 obligation under §41's same-deployable-unit note.
- §54.4 recorded that `cmd/server`, `internal/config` and `internal/network` had **no test files** at baseline. **Actual:** Task 1 adds the first tests to all three, which is §54.5 item 2's "with tests" requirement rather than a deviation.

### 57.9 Deliberately out of scope

No RouterOS write execution, connection, lease, retry, timeout classification, or `UNKNOWN_OUTCOME`; no migration, reservation, `router_credentials`, or `NETWORK_ENGINE_DEVICE_TOKEN`; no `MANAGED` transition or state change; no Laravel, billing, route, or UI change. Task 1 only makes the safe boundary exist and provable, so Task 2 (credential-backed `MATCHED` reconciliation) remains the next executable task.