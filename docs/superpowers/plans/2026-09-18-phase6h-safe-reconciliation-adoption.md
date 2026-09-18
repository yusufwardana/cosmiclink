# CosmicLink Phase 6H — Safe Reconciliation & Adoption Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extend the existing Phase 6C discovery/adoption foundation so an authenticated operator can explicitly map an observed PPPoE identity to an existing CosmicLink connection, while preserving read-only RouterOS behavior and preventing adoption from becoming management.

**Architecture:** Keep `NetworkDiscoveryService`, `AdoptDiscoveredNetworkResource`, `NetworkReconciliationService`, `NetworkAccount`, and `CustomerConnection` as the single reconciliation subsystem. `CustomerConnection.network_account_id` remains the authoritative operational mapping; `DiscoveredNetworkResource` remains the tenant/router-scoped evidence row that records the observed identity, reconciliation state, and adoption pointers. Laravel owns suggestions, authorization, transactions, audit history, and safety gates. Go and RouterOS remain observation-only.

**Tech Stack:** Laravel 12 / PHP 8.3, Eloquent, PostgreSQL-compatible relational schema and JSON columns, Blade UI, Go standard-library network engine, PHPUnit, Go tests.

**Spec:** User-provided “COSMICLINK — PHASE 6H SAFE RECONCILIATION & ADOPTION” request dated September 18, 2026.

## Global Constraints

- Worktree: `C:\xampp\htdocs\cosmiclink-phase6h`.
- Branch: `phase-6h-reconciliation-adoption`.
- Base commit and verified Phase 6G baseline: `a14a77e6d6c5ecc787cf2180e8a5e785fee288a9`.
- Keep `C:\xampp\htdocs\cosmiclink-phase6g` frozen.
- Do not merge master, reset Phase 6G, commit, or push during this plan-only task.
- RouterOS monitoring/discovery mutations remain `0`; no RouterOS write path may be called by reconciliation or adoption.
- Discovery never adopts; monitoring never adopts; billing never adopts.
- Only an authenticated, authorized operator confirmation may transition `DISCOVERED → ADOPTED`.
- `ADOPTED → MANAGED` is forbidden in Phase 6H; no MANAGED UI, API, job, observer, or automatic transition is added.
- No passwords are read, copied, generated, synchronized, logged, or required for adoption.
- No fuzzy, AI, Levenshtein, customer-name, or silent automatic matching.
- Prefer no migration; add a migration only if an existing invariant cannot be enforced safely with the current schema.
- Default Go changes: `0`; default RouterOS changes: `0`.

---

## 1. Executive decision summary

Phase 6H is **READY**, subject to the implementation gates below. Phase 6C already supplies the discovery snapshots, discovered resources, audits, fingerprints, `DISCOVERED`/`ADOPTED` state values, adoption service, reconciliation service, route, UI, and focused tests. The work is a hardening and adoption-authority phase, not a new subsystem.

The implementation should:

1. keep `CustomerConnection.network_account_id` as the canonical runtime mapping;
2. use deterministic, explainable suggestions only;
3. make adoption a locked, tenant-scoped local transaction;
4. reuse `network_discovery_audits` and preserve historical records;
5. make unadoption explicit and local-only;
6. separate adopted monitoring from provisioning/management permissions;
7. add billing and manual-operation safety gates so adopted accounts cannot be written to RouterOS;
8. leave the Go engine and real MikroTik configuration unchanged.

Expected implementation complexity is **medium**: mostly Laravel domain/service/controller/UI/test work, with a likely small safety migration only if uniqueness or provenance cannot be represented safely in current columns. No Go protocol work is expected.

## 2. Verified Phase 6G baseline

The Phase 6H worktree was created from exact commit `a14a77e6d6c5ecc787cf2180e8a5e785fee288a9`; `git rev-parse HEAD` was verified to equal that full SHA. The frozen Phase 6G worktree remains separate.

Phase 6G flow in this tree is:

```text
Laravel MonitoringService
  → GoMonitoringDriver
  → GoNetworkMonitoringClient
  → Go Network Engine
  → RouterOS monitoring provider
  → HealthObservation
  → OutageCorrelationService
```

`GoMonitoringDriver::observeConnection()` currently loads the connection’s router and `NetworkAccount`, then compares the account username with observed `ppp_active[].name`. `MonitoringService::observeTenant()` currently selects connections with non-null `provisioned_at`. This must remain compatible with existing provisioned accounts while adding explicit safe semantics for adopted accounts.

The Phase 6G safety contract remains unchanged: monitoring is read-only, no RouterOS mutation commands are added, and health failures become `UNKNOWN` rather than customer `OFFLINE` when the engine/router is unavailable.

## 3. Existing Phase 6C adoption/reconciliation audit

Existing reusable implementation:

- `app/Services/Network/NetworkDiscoveryService.php` persists sanitized snapshots, canonical SHA-256 fingerprints, resource rows, and discovery audit rows.
- `app/Models/NetworkDiscoverySnapshot.php` represents historical discovery runs.
- `app/Models/DiscoveredNetworkResource.php` stores `tenant_id`, `router_id`, `resource_type`, `fingerprint`, normalized data, management state, and nullable adoption pointers.
- `app/Models/NetworkDiscoveryAudit.php` stores actor, tenant, router, resource, action, safe details, and timestamp.
- `app/Services/Network/AdoptDiscoveredNetworkResource.php` already creates or reuses a local `NetworkAccount`, attaches it to a `CustomerConnection`, changes the resource to `ADOPTED`, and writes an `ADOPTION` audit.
- `app/Services/Network/NetworkReconciliationService.php` already emits `NEW`, `MATCHED`, `CHANGED`, `CONFLICT`, and `MISSING` outcomes.
- `routes/web.php` already exposes authenticated discovery and adoption routes.
- `tests/Feature/Phase6CNetworkDiscoveryTest.php` already proves sanitized discovery, explicit adoption, tenant isolation, reconciliation outcomes, repeat persistence, and zero network-operation logs.
- `tests/Feature/Phase6CLiveDiscoveryIntegrationTest.php` already plans/proves repeat discovery and zero Go mutation count when enabled.

Current gaps to close:

- adoption does not lock resource/connection/account rows;
- adoption does not reject an already-linked resource/account/connection conflict comprehensively;
- adoption trusts `normalized_data['username']` without explicit normalization/type validation;
- suggestions are not a first-class deterministic result with reason/evidence;
- there is no unadopt route/service;
- no policy is dedicated to discovered-resource adoption;
- target connection selection is not fully validated against account cardinality and tenant relationships;
- reconciliation currently uses latest snapshot ID rather than an explicit resource presence/evidence model and does not robustly distinguish relocation;
- adopted connections are not explicitly distinguished from provisioned/managed connections in monitoring and billing;
- UI presents a generic connection selector rather than an explainable review/confirmation flow;
- current schema has no database uniqueness preventing multiple discovered resources from pointing to one connection or one account from being attached to conflicting connections.

## 4. Current domain relationships

Verified relationships:

```text
Tenant
 ├─ Router
 │   ├─ NetworkAccount
 │   ├─ NetworkDiscoverySnapshot
 │   │   └─ DiscoveredNetworkResource
 │   └─ HealthObservation(subject_type=router)
 ├─ Customer
 │   └─ CustomerConnection
 │       ├─ InternetPackage
 │       ├─ Router
 │       ├─ NetworkAccount (nullable FK)
 │       ├─ HealthObservation(subject_type=connection)
 │       └─ OutageIncident pivot
 └─ NetworkDiscoveryAudit
```

The authoritative operational link is:

```text
CustomerConnection.network_account_id → NetworkAccount
```

The authoritative adopted-evidence link is:

```text
DiscoveredNetworkResource.network_account_id
DiscoveredNetworkResource.customer_connection_id
```

Those two discovered-resource pointers must always agree with the connection’s `network_account_id` after adoption. They are denormalized evidence pointers, not a replacement for the connection-to-account FK.

Do **not** create a new pivot/table in Phase 6H unless implementation proves the current one-to-one business rule cannot be enforced safely. Existing models support one `NetworkAccount` to many `CustomerConnection` rows at the ORM level, but adoption safety requires one active PPPoE identity to map to at most one active customer connection in a tenant/router scope.

## 5. Current DB/cardinality audit

Relevant existing schema:

- `network_accounts`: nullable `encrypted_secret`, `tenant_id`, `router_id`, `username`, `profile`, `status`, unique `(tenant_id, router_id, username)`.
- `customer_connections`: nullable `network_account_id`, `tenant_id`, `router_id`, customer/package/status fields, unique `(tenant_id, router_id, customer_id)`.
- `discovered_network_resources`: nullable `customer_connection_id`, nullable `network_account_id`, `management_state`, fingerprint unique `(tenant_id, router_id, resource_type, fingerprint)`.
- all discovery/audit tables have tenant and router foreign keys.

Current safe conclusions:

- `encrypted_secret` is nullable; an adopted existing account with unknown password can be represented without inventing a secret.
- `NetworkAccount.username` is unique per tenant/router, so duplicate usernames on different routers are valid and must not be treated as conflicts across routers.
- There is no unique constraint on `customer_connections.network_account_id`; application locking and a migration-backed partial/filtered uniqueness strategy must be evaluated for the configured database. If cross-database partial indexes are not used by this project, enforce the invariant in the transaction and add a regular supporting index.
- Existing foreign keys use cascade/null-on-delete behavior. Adoption history must survive resource unadoption and should remain as an audit row even if an associated resource is later deleted; if current `nullOnDelete` is retained, audit details must contain immutable IDs and safe identifiers.

Migration decision: **prefer no migration for the first implementation** because nullable secrets and adoption pointers already exist. Add only a minimal migration if the chosen database cannot safely enforce the one-account/one-active-connection invariant through existing constraints plus locked transactions. Do not add `adopted_at`/`adopted_by` columns merely for convenience; the audit table already records actor/time/state transition.

## 6. Discovery vs reconciliation vs adoption definitions

- **Discovery:** read-only observation of what exists on a router; persists a historical snapshot and sanitized resource evidence. It may upsert a resource but never changes management state from `DISCOVERED` and never creates a customer relationship.
- **Reconciliation:** deterministic comparison of current/most recent observed evidence against local records. It reports `NEW`, `MATCHED`, `CONFLICT`, `CHANGED`, `MISSING`, and an explicit suggestion result where appropriate. It never changes relationships.
- **Adoption:** authenticated operator confirmation that a specific discovered resource belongs to a specific same-tenant CosmicLink connection. It changes only local database relationships/state/audit records.

Discovery and reconciliation may run automatically; adoption may not.

## 7. Management state semantics

`DISCOVERED` means observed but not authoritative for a customer. It is observation-only.

`ADOPTED` means the operator confirmed the resource-to-account-to-connection relationship. It enables authoritative local mapping and customer-level monitoring, but does not grant RouterOS write permission.

`MANAGED` remains reserved for a later controlled-write phase. There must be no Phase 6H path, button, policy, observer, billing branch, or scheduled task that enters `MANAGED`.

State transitions allowed in 6H:

```text
DISCOVERED --explicit authenticated operator action--> ADOPTED
ADOPTED --explicit safe unadopt action, if preconditions hold--> DISCOVERED
```

All discovery, monitoring, billing, and reconciliation jobs must leave management state unchanged.

## 8. Deterministic matching strategy

Only exact, explainable suggestions are allowed.

For `pppoe_account`, normalize only the username representation required by the existing domain (trim and compare exact case according to the project’s chosen username policy; do not case-fold unless RouterOS/account semantics explicitly establish case-insensitivity). Candidate requirements:

1. same authenticated tenant;
2. same router ID;
3. same supported resource type;
4. exact normalized observed username equals `NetworkAccount.username`;
5. candidate account is not already incompatibly attached;
6. candidate connection is same router and tenant.

The suggestion payload must include the exact reason, for example: `exact username + same tenant + same router`, plus resource/account/connection IDs and the snapshot timestamp. Customer name is display context only and never a match key.

Classification should reuse existing vocabulary wherever possible:

- `NEW`: no local account/connection mapping;
- `MATCHED`: adopted mapping agrees with current evidence;
- `CHANGED`: adopted mapping exists but relevant observed fields differ;
- `CONFLICT`: mapping/account/tenant/router/cardinality contradiction;
- `MISSING`: adopted resource absent from the latest successful snapshot;
- `POSSIBLE_MATCH` or an equivalent suggestion-only status may be added only if it does not replace existing reconciliation statuses and is clearly non-mutating.

Do not introduce a second outcome enum if a structured `suggestion` field on the existing reconciliation result is sufficient.

## 9. Suggestion vs adoption boundary

Suggestion generation is read-only and may be displayed automatically. It must not write `network_account_id`, `customer_connection_id`, or `management_state`.

Adoption must require:

- authenticated operator session;
- authorization/policy check;
- explicit target connection selection;
- fresh resource state re-read inside a transaction;
- visible confirmation showing router, username, customer, connection, and exact reason;
- server-side precondition validation;
- audit row recording operator and before/after state.

The browser must never submit `tenant_id`; tenant context comes from `Auth::user()->tenant_id`.

## 10. Adoption preconditions

The adoption service must reject with a safe validation/conflict response when any condition fails:

1. resource tenant equals authenticated operator tenant;
2. target connection tenant equals authenticated operator tenant;
3. target connection customer tenant, package tenant, router tenant, and router ID are consistent;
4. resource type is `pppoe_account`;
5. resource state is exactly `DISCOVERED` for first adoption;
6. resource has a non-empty sanitized username;
7. resource’s router equals target connection’s router;
8. latest successful discovery for that router is not older than the configured adoption freshness window;
9. no unresolved conflict exists for the resource;
10. resource is not already adopted by another connection;
11. target connection is not already linked to a different network account;
12. the exact `(tenant, router, username)` account is either absent or compatible;
13. the compatible account is not attached to another incompatible active connection;
14. no cross-tenant or cross-router relationship is introduced;
15. adoption does not invoke `ProvisionCustomerConnection`, `NetworkOperationService`, or any driver.

If the resource is stale, the default behavior should be reject-and-refresh, not adopt stale evidence. The UI should tell the operator to run discovery again.

## 11. Adoption transaction design

Extend `app/Services/Network/AdoptDiscoveredNetworkResource.php` rather than create a second adoption service.

Within one `DB::transaction()`:

1. re-query the resource with `lockForUpdate()`;
2. re-query the target connection with `lockForUpdate()`;
3. re-query the same-tenant/router/username `NetworkAccount` with `lockForUpdate()`;
4. validate all preconditions using the locked rows;
5. create the local account only when absent, with `encrypted_secret = null`, safe metadata such as `adopted_from_discovery`, and observed profile only if the profile is a non-secret supported field;
6. never copy arbitrary normalized payload fields into account columns;
7. set `customer_connections.network_account_id`;
8. set both discovered-resource pointers and `management_state = ADOPTED`;
9. write a `NetworkDiscoveryAudit` with previous/new state, operator, account/connection IDs, safe username/router IDs, snapshot ID, and reason;
10. commit and return the fresh resource.

The service must not call the provisioning action, operation service, driver, Go client, or any event/listener that can do so. If model observers exist later, adoption should use an explicit local-mapping method/event that is separate from provisioning events.

## 12. Concurrency/locking strategy

Use database transactions and row locks, not only disabled buttons. Lock in a consistent order: discovered resource, target connection, then candidate account and any competing connection rows. Two simultaneous adoption requests for one resource must result in one success and one safe conflict/idempotent response.

Discovery refresh may run concurrently. Adoption must re-read the resource and latest successful snapshot within the transaction; if evidence changed or became stale, reject with a refresh-required result. Discovery must never overwrite adopted pointers or downgrade `ADOPTED` to `DISCOVERED`.

If a resource disappears while the dialog is open, adoption fails safely; it does not create a local relation from stale UI data.

## 13. Uniqueness/conflict rules

- One discovered PPPoE fingerprint may point to at most one connection.
- One `(tenant, router, username)` account may not be adopted into conflicting active connections.
- Same username on different routers is not a conflict; router identity is part of the key.
- Same username on the same router with changed profile is `CHANGED`, not a new identity, provided the fingerprint/resource identity rules confirm continuity.
- A resource moved to another router is a relocation candidate/conflict, never silently moved.
- A changed username is `CHANGED`/`CONFLICT` depending on whether identity continuity can be proven from existing stable identifiers; do not infer continuity from customer names.
- Deleting a customer connection or account must not delete RouterOS data. The discovered resource becomes `CONFLICT`/`UNMATCHED` and requires a new explicit decision.
- Router deletion must not cause cross-tenant adoption; cascading rows may disappear according to existing schema, while retained audit details remain non-secret historical evidence.

The implementation must document whether the database supports a partial unique index for active mappings. If not, enforce the invariant under locks and add an ordinary index for the conflict query.

## 14. NetworkAccount strategy

**A — Existing account matches:** lock and reuse the existing same-tenant/router/username account. Do not overwrite secret, status, or profile blindly. Copy only explicitly approved observed fields; profile changes are drift evidence and require separate operator action.

**B — Connection exists, account does not:** explicit adoption may create a local `NetworkAccount` with the observed username/router/tenant and nullable secret. This is a local recognition record, not provisioning. It must not call `NetworkOperationService`.

**C — Account exists unattached:** explicit adoption may attach it if all tenant/router/cardinality checks pass. Preserve its existing local metadata and nullable/unknown secret.

**D — Discovered resource has no CosmicLink customer:** leave it `DISCOVERED`; provide a suggestion or unmatched display only. Do not create a customer or connection automatically.

**E — Existing local account conflicts:** reject adoption and display the exact conflict (different router, different connection, changed username, or stale evidence). Do not repair automatically.

The account’s `profile` may be stored only as an observed non-secret attribute when compatible with the existing model. Passwords and secret-like fields are never copied.

## 15. CustomerConnection strategy

`CustomerConnection.network_account_id` is the only operational link used by monitoring and existing connection workflows. Adoption updates that FK only after explicit confirmation and successful precondition validation.

Adoption must not set `status = active`, `provisioned_at`, or a provisioning operation result. If an existing connection is already active/provisioned, adoption still must not be allowed to make billing or manual writes safe by implication; write eligibility is a separate management/provenance decision.

Connection deletion remains a local lifecycle action. It must not issue RouterOS deletion/disconnect commands as a side effect of unadoption or relationship cleanup.

## 16. Secret/password handling

The current `network_accounts.encrypted_secret` column is nullable, so no migration or fabricated credential is required for an adopted existing account.

Discovery sanitization already removes `password`, `pass`, `secret`, `token`, authorization, and credential keys. Phase 6H must retain and strengthen this recursive sanitization for snapshots, normalized data, audit details, UI payloads, logs, exceptions, API responses, and test fixtures.

Never call `NetworkAccount::setSecret()` during adoption. Never ask RouterOS for PPPoE secrets. Never display a secret-presence boolean in a way that leaks sensitive operational detail unless explicitly required by existing UI policy.

## 17. Drift/reconciliation model

Reconciliation compares the adopted local identity to the latest successful observed resource for the same router and resource identity:

```text
expected username + router A / observed same → MATCHED
expected adopted resource absent from latest successful snapshot → MISSING
same identity with relevant profile/config difference → CHANGED
same username on router B or incompatible account/connection → CONFLICT / relocation candidate
no adopted mapping → NEW plus deterministic suggestion, if any
```

Reconciliation is report-only. It must never update account profile, enable/disable state, connection status, management state, or RouterOS.

## 18. Repeat discovery behavior

Repeat discovery must upsert by the existing tenant/router/resource-type/fingerprint key, preserve the existing `ADOPTED` state and pointers, update `last_seen_at` and latest snapshot evidence, and add only the normal discovery audit. It must never create a duplicate account, duplicate connection mapping, or downgrade adoption.

If a changed fingerprint represents a changed resource, retain the old adopted evidence for drift/history and create or classify the new resource according to existing reconciliation rules. Do not silently transfer adoption to the new fingerprint.

## 19. Missing/changed resource behavior

Missing is derived from absence in the latest successful snapshot for the same router; failed discovery must not falsely mark all resources missing. A missing resource remains locally adopted for history but is not silently detached or deleted.

Changed evidence produces a visible `CHANGED` or `CONFLICT` result with the observed fields that differ, excluding secrets. No repair is performed. A later matching discovery can return the result to `MATCHED` without changing the local relationship.

## 20. Unadopt/reversal decision

Implement `ADOPTED → DISCOVERED` as an explicit local-only operator action, because it is necessary to correct an operator mistake without deleting or changing an existing RouterOS account.

Strict unadopt rules:

- authenticated same-tenant operator and dedicated authorization;
- lock resource, connection, and account rows;
- resource is currently `ADOPTED` and pointers agree;
- no later adoption is concurrently in progress;
- clear `customer_connection_id` and `network_account_id` on the discovered resource;
- clear `customer_connections.network_account_id` only when it still points to that exact adopted account and no other approved local relation requires it;
- set resource state to `DISCOVERED`;
- preserve `NetworkAccount` by default as a local record unless a separate local cleanup policy explicitly says otherwise;
- write a reversal audit row with previous/new state and reason;
- never delete, disable, disconnect, or modify RouterOS.

No historical audit row is edited or deleted.

## 21. Audit trail design

Reuse `network_discovery_audits`. Add actions only as needed: existing `DISCOVERY` and `ADOPTION`, plus `UNADOPTION`, `ADOPTION_CONFLICT`, or `ADOPTION_REJECTED` if the existing action vocabulary supports them without ambiguity.

Each adoption/reversal record must include tenant ID, operator ID, resource ID, router ID, target connection/account IDs, previous state, new state, snapshot ID, safe username/resource type, timestamp, and optional operator reason. It must not include passwords, encrypted secrets, raw provider payloads, authorization headers, or arbitrary normalized JSON.

## 22. Tenant isolation

Tenant ID is always derived from authenticated context. Every list, suggestion, resource lookup, target connection lookup, account lookup, adoption, unadoption, reconciliation, monitoring mapping, and audit query is scoped by `Auth::user()->tenant_id` or an already-authorized model relation.

Prove that Tenant A cannot see or adopt Tenant B resources, target Tenant B connections/accounts, reverse Tenant B adoption, or infer Tenant B usernames through validation errors. Route model binding alone is insufficient; controller/service checks remain mandatory.

## 23. Authorization/policies

Add a dedicated policy or gate for `DiscoveredNetworkResource` adoption/unadoption if the project’s current policy registration pattern supports it. At minimum, require authenticated tenant ownership and an operator role permitted to reconcile network resources; read-only roles may view suggestions but cannot adopt/unadopt.

Keep router `operate` authorization for discovery execution. Do not reuse an unrestricted account `status` action for adoption. Authorization must be checked both in the controller and service boundary for defense in depth.

## 24. API design

Follow the existing authenticated web/API style rather than inventing a separate public contract prematurely.

Preferred minimal additions/extensions:

- authenticated `GET /network/discovery` remains the reconciliation workspace;
- existing `POST /network/discovery/resources/{resource}/adopt` is retained and hardened;
- add authenticated `POST /network/discovery/resources/{resource}/unadopt`;
- optionally add an authenticated JSON reconciliation endpoint under the existing `/api/v1` group only if the UI needs it.

No endpoint accepts arbitrary `tenant_id`. Adoption accepts only the target connection ID and optional operator reason. Server-side response errors must distinguish forbidden, stale, conflict, and validation failure without leaking cross-tenant existence.

## 25. UI reconciliation workspace

Extend `resources/views/network/discovery.blade.php` or the existing UI convention into a clear “Network > Reconciliation” workspace.

Each discovered PPPoE row should display:

- username/resource identity;
- router;
- management state;
- latest observed time and snapshot freshness;
- reconciliation outcome;
- deterministic suggestion and exact reason;
- target customer/connection/account details;
- `Review`, `Confirm Adoption`, and, after adoption, `Unadopt` actions where authorized.

The confirmation must explicitly state:

```text
This associates an existing network identity locally.
It will not change RouterOS, PPPoE password, session state, profile, firewall, or management mode.
It will not mark the connection MANAGED.
```

No manage/enable/suspend/provision button is added to this workspace.

## 26. Customer 360 changes

Extend the existing customer show view/controller load path to show, for each connection:

- PPPoE username;
- router;
- `ADOPTED` versus legacy/provisioned ownership semantics;
- reconciliation state (`MATCHED`, `MISSING`, `CHANGED`, `CONFLICT`);
- observed online/offline health;
- last discovery and last monitoring timestamps;
- “Future controlled management: DISABLED.”

Do not add a MANAGED enable button. Customer 360 is display-only for these relationships.

## 27. Phase 6G monitoring integration

Do not redesign `MonitoringService` or `GoMonitoringDriver` unnecessarily. Preserve legacy/provisioned mappings: a provisioned CosmicLink-owned connection with a `NetworkAccount` must remain monitorable.

Introduce explicit semantics in Laravel, preferably through a small mapping/provenance helper or query scope:

- `PROVISIONED_LOCAL`: created by CosmicLink provisioning; existing Phase 6G behavior remains valid;
- `ADOPTED_EXISTING`: linked by explicit adoption; monitorable through the same username lookup;
- `UNMAPPED`: no authoritative account; return `UNKNOWN` with `UNMAPPED_CONNECTION`;
- `CONFLICT/DRIFT`: retain monitoring only if the mapping remains unambiguous, and expose reconciliation state; never auto-repair.

Monitoring selection must not be based on `management_state = ADOPTED` alone, because legacy provisioned accounts may have no discovered-resource row and must continue to work. Conversely, adoption must not set `provisioned_at` merely to enter the monitoring query. The plan should either broaden the selection to explicit authoritative mapping (`network_account_id` plus allowed provenance) or add a safe local mapping scope without implying management.

Customer observations continue to be persisted against `CustomerConnection`; the adopted resource is traceability metadata, not a second health subject. Outage correlation consumes the same observations and must not create a duplicate incident system.

## 28. Outage Intelligence integration

Keep `app/Services/Monitoring/OutageCorrelationService.php` unchanged unless a query guard is required. It already consumes connection observations and groups active connections by router. Phase 6H must ensure adopted connections that are intentionally eligible for monitoring can participate, while unmapped or stale/conflicted rows remain `UNKNOWN`/excluded from false customer outages.

Add tests proving an adopted connection can produce a real `HealthObservation` and enter existing outage correlation, while engine failure or unmapped identity does not open an outage.

## 29. Database changes, preferably none

Expected initial database change: **none**.

Existing nullable secret and adoption pointer columns are sufficient for the relationship. Before implementation, inspect actual database driver/index capabilities. If a migration is necessary, it may only:

- add a supporting index for tenant/router/account conflict queries;
- add a database-enforceable uniqueness constraint for one active account-to-connection mapping if compatible with the project’s database;
- add a nullable explicit provenance column only if monitoring/billing safety cannot be expressed from current data without ambiguity.

Any migration must include a justification, backfill/compatibility behavior for existing Phase 6G accounts, and a down path. Do not add timestamps/operator columns when the audit table already provides them.

## 30. Go changes, preferably none

Go changes: **0 expected**. Discovery already emits sanitized normalized PPPoE account data and monitoring already emits active usernames. Reconciliation/adoption is tenant/customer/business logic and belongs in Laravel.

If an implementation discovers a missing stable observation field, first evaluate whether the existing `external_ref`, username, router ID, and fingerprint are sufficient. A Go change requires a separate justification, read-only test, and proof that no mutation route or permission is introduced.

## 31. RouterOS safety

RouterOS changes: **0**. No new permissions, commands, API calls, provisioning, profile changes, disconnects, enables, disables, password reads, or arbitrary commands are allowed.

Real hardware acceptance, if performed, is read-only discovery/monitoring only. Capture a pre-fingerprint and post-fingerprint of the relevant safe read-only configuration; require equality. If there is no safe existing customer resource to map, hardware adoption remains `NOT EXERCISED` rather than manufacturing a RouterOS account.

## 32. Fake E2E plan

Use the existing fake discovery and monitoring paths:

```text
fake discovery
 → resource DISCOVERED
 → deterministic suggestion with reason
 → operator confirmation
 → local ADOPTED relationship
 → repeat discovery preserves ADOPTED
 → Go/fake monitoring maps username
 → HealthObservation for CustomerConnection
 → existing OutageCorrelationService path
```

Assertions: relationship changes only after adoption; no `NetworkOperationLog`; no driver mutation calls; no Go mutation endpoint; no password required; resource remains ADOPTED after repeat discovery; HealthObservation is traceable to the adopted connection; mutation count remains zero.

## 33. Optional real hardware acceptance

Only if a safe existing hEX PPPoE identity is available and the operator explicitly approves the test data: run read-only discovery, review deterministic mapping, perform local adoption, run read-only monitoring, and compare pre/post safe fingerprints. Do not create a fake account or alter RouterOS to make the test possible.

Record `NOT EXERCISED` when no safe resource exists. This is not a failure of the local plan.

## 34. Focused tests

Extend `tests/Feature/Phase6CNetworkDiscoveryTest.php` or create a focused `Phase6HReconciliationAdoptionTest.php` with tests for:

- exact same-tenant/same-router/normalized-username suggestion;
- suggestion is read-only;
- explicit adoption succeeds;
- unauthenticated adoption is rejected;
- cross-tenant resource and target rejection;
- duplicate adoption is idempotent or a safe conflict;
- two resources cannot map to one connection incompatibly;
- one account cannot map to conflicting connections;
- stale resource adoption is rejected until refresh;
- profile/identity drift yields CHANGED/CONFLICT;
- adoption creates no `NetworkOperationLog`;
- adoption never calls `NetworkDriver` or Go mutation routes;
- adoption succeeds with `encrypted_secret = null` and no generated password;
- ADOPTED never becomes MANAGED;
- overdue billing and payment flows do not mutate adopted existing accounts;
- adopted mapping produces monitoring observations;
- unmapped resource remains safe/UNKNOWN;
- missing resource reports MISSING;
- repeat discovery preserves adoption;
- unadopt clears only local relationships and preserves RouterOS/account history;
- concurrent adoption is serialized safely;
- audit rows contain operator/state transitions and no secrets;
- tenant isolation and authorization policy behavior.

Use mocks/spies around `NetworkOperationService`, `NetworkDriver`, and HTTP clients to prove zero mutation calls.

## 35. Regression tests

Run and preserve the existing suites for Phase 1/1.1, Phase 2/2.1, Phase 4A/B/C, Phase 6C, Phase 6D, Phase 6E, Phase 6F, and Phase 6G. Pay special attention to:

- provisioning still creates/monitors legacy accounts;
- existing Phase 6G Go monitoring payload parsing remains unchanged;
- engine/router failures remain UNKNOWN;
- outage correlation still uses one existing incident system;
- agent/discovery paths remain read-only;
- account management routes retain their existing authorization and are not accidentally made available to adopted accounts.

## 36. Security/secret tests

Test recursive sanitization for passwords, pass, secrets, tokens, credentials, authorization, nested arrays, audit details, API responses, validation errors, and exception messages. Assert database snapshots, resource normalized data, rendered HTML, and operation/audit logs contain no test secret markers.

Test that browser-supplied tenant IDs are ignored/rejected and that cross-tenant existence is not disclosed through different error messages.

## 37. Zero-mutation acceptance

The acceptance suite must prove all of the following remain zero:

```text
RouterOS mutation commands
Go mutation additions
PPPoE create / enable / disable / disconnect
PPPoE password read / change
profile / firewall changes
automatic adoption
MANAGED transitions
billing-triggered RouterOS operations for ADOPTED rows
arbitrary RouterOS commands
discovery → adoption without operator confirmation
adoption → MANAGED
```

Also assert `network_operation_logs` remains unchanged during discovery, suggestion, reconciliation, adoption, unadoption, and monitoring-only paths.

## 38. Exact files expected to change/create

Expected Laravel files:

- Modify: `C:\xampp\htdocs\cosmiclink-phase6h\app\Services\Network\AdoptDiscoveredNetworkResource.php`
- Modify: `C:\xampp\htdocs\cosmiclink-phase6h\app\Services\Network\NetworkReconciliationService.php`
- Modify: `C:\xampp\htdocs\cosmiclink-phase6h\app\Services\Network\NetworkDiscoveryService.php` only if freshness/identity normalization needs a shared helper
- Modify: `C:\xampp\htdocs\cosmiclink-phase6h\app\Http\Controllers\NetworkDiscoveryController.php`
- Modify: `C:\xampp\htdocs\cosmiclink-phase6h\routes\web.php`
- Modify: `C:\xampp\htdocs\cosmiclink-phase6h\resources\views\network\discovery.blade.php`
- Modify: `C:\xampp\htdocs\cosmiclink-phase6h\app\Services\Monitoring\MonitoringService.php` and/or `GoMonitoringDriver.php` only for explicit mapping/provenance semantics
- Modify: `C:\xampp\htdocs\cosmiclink-phase6h\app\Actions\ProcessOverdueBilling.php`, `SuspendCustomerConnection.php`, `ReactivateCustomerConnection.php`, and manual account-operation authorization only to guarantee ADOPTED never triggers RouterOS writes
- Modify: `C:\xampp\htdocs\cosmiclink-phase6h\app\Http\Controllers\CustomerController.php` and `C:\xampp\htdocs\cosmiclink-phase6h\resources\views\customers\show.blade.php`
- Add or modify policy/authorization registration following the actual project convention
- Add: `C:\xampp\htdocs\cosmiclink-phase6h\tests\Feature\Phase6HReconciliationAdoptionTest.php` (preferred focused suite)
- Modify: `C:\xampp\htdocs\cosmiclink-phase6h\tests\Feature\Phase6CNetworkDiscoveryTest.php` only for regression coverage if duplication is avoided
- Modify: `C:\xampp\htdocs\cosmiclink-phase6h\tests\Feature\Phase6GMonitoringTest.php` for adopted/legacy monitoring semantics
- Optional migration only after schema/index audit proves it necessary

Expected untouched:

- `network-engine` Go source and mutation handlers;
- RouterOS provider/allowlist;
- MikroTik configuration and permissions;
- unrelated Vue modules, billing UI, messaging, agents, and topology features.

## 39. Risks

- Existing `customer_connections.network_account_id` has no database uniqueness; transaction locking must be correct, and a supporting migration may be needed.
- `provisioned_at` currently doubles as monitoring eligibility; broadening it carelessly could make adopted rows enter billing automation. Monitoring eligibility and write eligibility must be separated.
- Existing account-management routes can write any tenant account; adopted provenance must be checked before status/profile/disconnect operations.
- Fingerprint identity based on full normalized data may classify profile changes as new resources; implementation must preserve Phase 6C behavior while making drift visible.
- Failed discovery must not mark resources missing.
- Unadoption can leave a local orphan `NetworkAccount`; preserve it by default and make cleanup a separate explicit local operation.
- Model events/observers added later could accidentally provision during local adoption; tests must spy on the complete mutation path.
- Cross-database index behavior may differ; verify the project’s actual test/production driver before choosing a constraint.

## 40. Non-goals

No real RouterOS writes, provisioning, Smart Auto-Isolation, billing enforcement redesign, MANAGED mode, automatic adoption, fuzzy/AI matching, password synchronization, configuration repair, automatic drift repair, topology/PostGIS, OLT/AP/CPE support, ticketing, technician workflow, WhatsApp, QRIS, agent enrollment, or new incident system.

## 41. Rollback strategy

Because the requested task is plan-only, no rollback is required now. For implementation:

- revert the local adoption/reconciliation service/controller/UI changes as one release unit;
- disable the reconciliation/adoption routes behind configuration if a staged rollout is needed;
- leave discovery snapshots/audits intact as historical evidence;
- unadopt locally before disabling the feature if an operator must remove mappings;
- never rollback by deleting or modifying RouterOS accounts;
- if a migration is added, provide a tested down path and preserve nullable secrets/data.

## 42. Final verification gates

Implementation is complete only when all gates pass:

1. worktree remains based on `a14a77e6d6c5ecc787cf2180e8a5e785fee288a9` and Phase 6G worktree is unchanged;
2. focused Phase 6H/6C/6G tests pass;
3. full `php artisan test` passes;
4. Go `gofmt`, `go test ./...`, and `go vet ./...` pass if Go is untouched as a regression check;
5. `vendor\\bin\\pint --test` passes;
6. `npm run build` passes if the existing frontend build is part of the project gate;
7. `git diff --check` passes;
8. tenant isolation, authorization, concurrency, stale evidence, conflict, unadopt, and secret tests pass;
9. existing Phase 6G monitoring of provisioned accounts remains green;
10. adopted account monitoring maps to the adopted username without requiring a password;
11. adopted billing overdue/payment/manual actions make zero RouterOS writes;
12. no Go mutation endpoint or `NetworkDriver` method is called by adoption;
13. `network_operation_logs` count is unchanged by discovery/reconciliation/adoption/unadoption;
14. no `MANAGED` transition exists or occurs;
15. fake E2E proves discovery → suggestion → explicit adoption → monitoring → HealthObservation with mutation count `0`;
16. optional hEX read-only acceptance proves pre/post fingerprint equality, or is recorded `NOT EXERCISED` when no safe resource exists;
17. final inspection confirms only the documented files changed and no secrets are present.

## Final plan status

**PHASE 6H PLAN — READY**

Summary:

- **Authoritative relationship:** `CustomerConnection.network_account_id → NetworkAccount`; discovered resource stores synchronized evidence/adoption pointers.
- **Migration required:** preferably none; current nullable secret and adoption columns are sufficient. Add only a justified uniqueness/supporting-index migration if transaction-only enforcement is inadequate.
- **Adoption transaction:** authenticated tenant-scoped transaction with row locks, freshness/conflict checks, local account reuse/creation with null secret, connection/resource linkage, audit row, and zero network-driver calls.
- **Deterministic suggestion:** exact normalized username + same tenant + same router + compatible account/connection; explain the reason; never mutate.
- **Unadopt:** support explicit `ADOPTED → DISCOVERED`; clear only local relationship pointers, preserve account/history, and never touch RouterOS.
- **Monitoring integration:** retain legacy/provisioned monitoring and add explicit adopted-existing mapping semantics; do not use ADOPTED as the only monitoring selector and do not use `provisioned_at` as an adoption side effect.
- **Go changes:** `0` expected.
- **RouterOS changes:** `0`.
- **Major safety invariants:** no automatic adoption, no MANAGED transition, no secret handling, no billing/manual writes for adopted existing accounts, no mutation logs, strict tenant isolation, and zero RouterOS mutation commands.
- **Expected complexity:** medium, centered on Laravel hardening, authorization, cardinality/concurrency controls, safe billing/manual-operation gates, operator UI, and focused regression tests.