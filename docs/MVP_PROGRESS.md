# CosmicLink MVP Progress

## Status

**Phase 0 — Foundation + Network Simulation: VERIFIED**

The Laravel application runs locally, the automated Phase 0 tests pass, and
manual HTTP smoke verification has completed successfully.

## Project baseline

- Laravel 12.69.2
- PHP 8.3.32
- SQLite by default; MySQL and PostgreSQL PDO extensions are available
- PHPUnit 11 via the Laravel test runner
- Database queue/cache/session drivers from the Laravel 12 skeleton
- Blade views; no React, Vue, Inertia, Docker, or tenancy package added

## Architecture decisions

- Tenant ownership is explicit through `tenant_id` foreign keys.
- Router access is enforced server-side through `RouterPolicy` and `Gate`.
- Business network behavior depends on `NetworkDriver`.
- Phase 0 binds only `FakeNetworkDriver` when `NETWORK_DRIVER=fake`.
- Router passwords are encrypted with Laravel `Crypt` and hidden from views.
- Network-changing operations are written to `network_operation_logs` with safe
  payloads and nullable initiating user IDs.
- The actual hardware target is RouterOS v6.49.13, but real RouterOS API
  communication is intentionally not implemented.

## Database changes

- `tenants`
- `users.tenant_id` and `users.role`
- `routers`
- `network_accounts`
- `network_operation_logs`

Important constraints include tenant foreign keys, tenant-aware indexes, and
unique `(tenant_id, router_id, username)` network accounts.

## Important files

- `app/Services/Network/NetworkDriver.php`
- `app/Services/Network/FakeNetworkDriver.php`
- `app/Services/Network/NetworkOperationService.php`
- `app/Policies/RouterPolicy.php`
- `app/Http/Controllers/RouterController.php`
- `app/Http/Controllers/NetworkAccountController.php`
- `config/network.php`
- `routes/web.php`
- `tests/Feature/Phase0FoundationTest.php`
- `tests/Unit/FakeNetworkDriverTest.php`

## Seed data

`php artisan migrate:fresh --seed` creates development-only DemoNet data:

- Tenant: `DemoNet ISP`
- Owner: `owner@demonet.test`
- Router: `Demo Router 01`
- Profiles: `HOME-10M`, `HOME-20M`, `HOME-50M`
- Accounts: `cust001`, `cust002`, `cust003`

The owner password is `development-only-password` and must never be reused in
production.

## Tests executed

```text
php artisan test
  Tests: 13 passed (57 assertions)
```

Focused Phase 0 tests cover authentication, guest rejection, router CRUD,
cross-tenant router access, encrypted credentials, cross-tenant commands, all
fake account operations, failure logging, secret redaction, and log isolation.

## How to run simulation mode

Set the environment variable:

```dotenv
NETWORK_DRIVER=fake
```

Then run:

```bash
php artisan migrate --seed
php artisan serve
```

The dashboard displays **SIMULATION MODE** when the fake driver is active.

## Manual verification

Using `php artisan serve --host=127.0.0.1 --port=8010`:

- `GET /login` returned HTTP 200 and rendered the CosmicLink login page.
- Guest `GET /dashboard` returned HTTP 302 to the login route.

## Known limitations and technical debt

- No production RouterOS implementation exists.
- `RouterOsV6NetworkDriver` is reserved for a later phase and must target the
  RouterOS API/API-SSL service for v6.49.13.
- Login is intentionally basic; password reset, email verification, and MFA
  are not included in Phase 0.
- The current workspace has no Git history or configured remote.
- Full interactive browser CRUD verification remains optional; HTTP routing and
  behavior are covered by the feature suite.

## Phase 1 — Customer + Package + Connection + Zero-Touch Provisioning: VERIFIED

Phase 1 preserves the Phase 0 foundation and adds the first usable ISP workflow:

```text
Customer -> CustomerConnection -> InternetPackage + Router
         -> ProvisionCustomerConnection -> NetworkOperationService
         -> NetworkDriver -> FakeNetworkDriver -> NetworkAccount
```

### Architecture and data model

- `Customer` stores business identity and customer relationship status only.
- `InternetPackage` stores tenant-owned speed, integer Rupiah pricing, and the
  RouterOS profile name used by provisioning.
- `CustomerConnection` stores the subscribed service and lifecycle state. It is
  deliberately separate from infrastructure state in `NetworkAccount`.
- Customer connections reference the customer, package, router, and optional
  network account through foreign keys and tenant-scoped application checks.
- Customer and connection codes are assigned after insert as `CL000001` and
  `CON000001`. Database uniqueness remains the final collision protection.
- Package prices are retained for Phase 2 invoice snapshots; changing a package
  later must not rewrite historical invoice amounts.

### Zero-Touch Provisioning

The dedicated `ProvisionCustomerConnection` action performs short database state
transitions to `provisioning`, then calls `NetworkOperationService` outside a
long-running transaction. It generates the PPPoE username from the customer code,
gets the profile from `InternetPackage.network_profile`, creates or reuses the
tenant/router/username `NetworkAccount`, and marks the connection `active` only
after a successful driver result.

Unavailable routers produce `failed` connections with a sanitized failure code,
message, and timestamp. Retrying a failed connection reuses the existing account;
repeated provisioning of an active connection is a no-op. Database uniqueness and
`firstOrCreate` provide duplicate protection in addition to request-level checks.
This short-boundary design leaves room for future queued retries and reconciliation
when a real external driver exists.

PPPoE secrets are generated with cryptographically secure Laravel random bytes,
encrypted in `network_accounts.encrypted_secret`, hidden by the model, and removed
by the existing operation-log sanitizer. Secrets are not rendered in Blade, URLs,
error messages, or operation payloads.

### Tenant isolation and UI

Customer, package, and connection policies enforce tenant ownership. Controllers
derive the tenant from the authenticated user and verify package/router ownership
before connection creation; submitted tenant IDs are not trusted. Customer detail
provides the Phase 1 Customer 360 foundation with contact data, connections,
package speed, router, status, PPPoE username, provisioning time, and recent
network operations. The dashboard reports tenant-scoped customers and connection
states. The existing manual PPPoE page remains available as **Network Lab /
Simulated PPPoE**, separate from the normal customer provisioning workflow.

### Verification

- `php artisan test`: 18 passed (85 assertions), including all Phase 0 tests.
- `vendor/bin/pint --test`: PASS (68 files).
- `php artisan migrate:fresh --seed --force`: PASS.
- Demo seed data includes HOME-10, HOME-20, HOME-50, Budi Santoso, Siti Rahma,
  Andi Pratama, and internally consistent connections/accounts.

## Phase 1.1 — Final Verification + Audit Trace Hardening

### Status

**Phase 1.1 — VERIFIED for automated and database verification; browser workflow
manual verification is NOT TESTED in this environment.**

`network_operation_logs` now has an optional, indexed
`customer_connection_id`. It remains nullable for standalone router tests,
Network Lab commands, and future router maintenance operations. The
`NetworkOperationService` accepts this context only where applicable; the
`NetworkDriver` abstraction and `FakeNetworkDriver` implementation are unchanged.

Provisioning passes the connection context for every create-account attempt.
Successful provisioning, failed provisioning, and retry attempts therefore all
remain independently auditable against the same CustomerConnection without
storing customer secrets or unnecessary PII in JSON payloads.

Customer 360 operation history now queries through the authenticated tenant's
`CustomerConnection` IDs. It no longer attributes operations merely because a
customer shares a router with another customer. The operation list displays the
safe connection code when a trace exists and continues to hide credentials and
secrets.

### Phase 1.1 verification evidence

- Phase 0 regression: **VERIFIED**, preserved baseline was 13 tests / 57
  assertions; complete Phase 1.1 suite is 20 tests / 90 assertions.
- Trace regression: **VERIFIED**, successful, failed, retry, and cross-customer
  Customer 360 attribution tests pass.
- Secret regression: **VERIFIED**, existing redaction tests and Phase 1 secret
  assertions remain passing.
- `vendor/bin/pint --test`: **VERIFIED PASS**, 68 files.
- `php artisan migrate:fresh --seed --force`: **VERIFIED PASS**.
- Manual success browser workflow: **NOT TESTED**; no browser walkthrough was
  exercised during this verification run.
- Manual failure/retry browser workflow: **NOT TESTED**; the current UI has no
  safe authenticated simulation control for changing router availability, so no
  control was added solely for this verification task.

The data model now supports future trustworthy queries from Router to
CustomerConnections to Customers and from CustomerConnections to their network
operation logs. Outage detection, monitoring, notifications, and AI remain out
of scope.

### Limitations and out of scope

- `FakeNetworkDriver` is the only network implementation; real RouterOS and
  `RouterOsV6NetworkDriver` are **NOT IMPLEMENTED**.
- Billing, invoices, payments, QRIS, WhatsApp, suspension automation, monitoring,
  outage intelligence, ticketing, inventory, portals, and AI remain out of scope.
- Cross-table tenant consistency is enforced in policies/controllers/actions plus
  tests; the separate tables use local foreign keys and tenant indexes rather than
  composite cross-table foreign keys.
- No queue or distributed idempotency key exists yet; future external operations
  will need durable reconciliation for lost responses.