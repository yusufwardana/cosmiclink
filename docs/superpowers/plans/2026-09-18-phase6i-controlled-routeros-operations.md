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
| 14 | `RouterPolicy::operate()` delegates to `view()` and only compares `tenant_id`, so a `customer`-role user in the tenant passes today; `User.role` is a plain string column `admin\|manager\|customer` | `app\Policies\RouterPolicy.php:10-28`, `NetworkAccountController.php:23` and `authorizeAccount()`, `database\migrations\2026_09_16_000002_add_tenant_and_role_to_users_table.php` | The Section 10 gap is concrete: the 6I policy must require an operator role and re-verify tenancy even where a policy already passed |
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

# PHASE 6I PLAN — READY

Summary: MANAGED is a narrow three-operation manual authorization; unknown PPPoE passwords are acceptable for that set; the global mutation switch is default-off; `MATCHED` fresh reconciliation and fresh `ONLINE` router health are mandatory; Phase 2 billing is isolated from real writes; idempotency and unknown outcomes fail closed; migration is YES for implementation; expected changes are centralized Laravel gate/service/policies/controllers/config, the existing Go API/provider boundary, and Blade-only safety UI; future hardware acceptance uses a manually designated test account and expected-delta fingerprints.