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

## Phase 2 — Billing Engine + Smart Auto-Isolation + Auto-Reactivation

### Architecture

Phase 2 keeps financial state separate from network state:

```text
Invoice -> Payment
    |
    v
CustomerConnection -> NetworkAccount -> NetworkOperationService
                                  -> NetworkDriver -> FakeNetworkDriver
```

`Invoice`, `InvoiceItem`, `Payment`, and `BillingAutomationAttempt` are separate
domain entities. NetworkOperationLog remains a network audit record and is not
used as the sole billing audit trail. Billing actions call dedicated network
actions, never `FakeNetworkDriver` directly.

### Invoice lifecycle and billing period

Invoices use integer Indonesian Rupiah values. The lifecycle is:

```text
unpaid -> overdue -> paid
draft/cancelled are reserved states; cancelled invoices are never enforced.
```

Overdue begins strictly after `due_date` (`due_date < today`). Normal recurring
billing is eligible for provisioned `active` and billing-suspended `suspended`
connections. This prevents a billing suspension from silently stopping future
charges. There is no proration, tax, late fee, discount engine, or cancellation
workflow in Phase 2.

Invoice numbers are assigned after insert from the persisted invoice ID in the
format `INV-YYYYMM-000001`, with a unique database constraint. Generation uses
`GenerateInvoiceForConnection` and `GenerateMonthlyInvoices`, with a unique
tenant/connection/billing-period constraint and price/description snapshots in
InvoiceItem. Changing a package price does not change an existing invoice.

### Payments and financial integrity

Manual payments support `cash`, `bank_transfer`, and `manual`. Partial payments
increase `paid_amount` while retaining `unpaid` or `overdue` status. Overpayments,
duplicate payment references, cancelled invoices, and already-paid invoices are
rejected. Full payment marks the invoice `paid` and records `paid_at`.

Payment database transactions cover only local financial writes. Network calls are
performed after that transaction, so a legitimate payment is never rolled back by
a router failure.

### Overdue policy and Smart Auto-Isolation

`MarkOverdueInvoices` transitions only unpaid invoices with an outstanding balance
and a due date before the application date. `ProcessOverdueBilling` enforces an
overdue invoice only when its connection is provisioned and active. Successful
`SuspendCustomerConnection` calls `NetworkOperationService`, then sets:

```text
NetworkAccount.status = disabled
CustomerConnection.status = suspended
CustomerConnection.suspension_reason = billing_overdue
```

Customer.status is never changed by billing. Repeated enforcement does not issue a
second successful suspension for an already-processed connection. If the network
operation fails, the invoice remains overdue, the connection remains active, the
account is not falsely disabled, and the billing attempt is recorded as failed.

### Auto-Reactivation

Full payment invokes `ReactivateCustomerConnection` only when the connection is
suspended for `billing_overdue`. Manual suspension is not auto-reactivated. A
connection is reactivated only when no other unpaid/overdue outstanding invoice
remains for that connection. Successful reactivation enables the NetworkAccount,
sets the connection active, and clears suspension fields. If the router is
unavailable, the invoice/payment remain valid and paid while the connection stays
suspended; the failed automation attempt and failed traced network operation are
retained.

### Billing audit and tenant isolation

`BillingAutomationAttempt` records tenant, invoice, connection, action, status,
timestamps, and sanitized failure information. Billing-triggered enable/disable
operations also carry `NetworkOperationLog.customer_connection_id`.

Invoice and payment pages and actions are tenant-scoped through policies and
server-side ownership checks. Request tenant/customer/connection ownership is not
trusted. The dashboard exposes unpaid/overdue counts, outstanding amount,
billing-suspended connections, and recent payments. The UI supports invoice list,
invoice detail, generation, manual payment entry, payment history, and overdue
enforcement. Artisan commands call the same services:

```text
billing:generate [--period=YYYY-MM]
billing:mark-overdue
billing:enforce
```

These commands are not scheduled automatically; Laravel Scheduler can invoke them
later without duplicating business logic.

### Phase 2 verification

- Automated suite: **VERIFIED**, 34 tests and 143 assertions; Phase 0, Phase 1,
  Phase 1.1, and Phase 2 tests remain passing.
- `vendor/bin/pint --test`: **VERIFIED PASS**, 95 files.
- `php artisan migrate:fresh --seed --force`: **VERIFIED PASS**.
- `git diff --check`: **VERIFIED PASS**.
- Seed data includes paid, unpaid, and overdue invoices with invoice items and a
  representative manual payment. The seeded overdue connection is active before
  any enforcement action, with no pre-seeded suspension audit row.
- Manual browser verification: **NOT TESTED** if a browser runtime is unavailable;
  automated HTTP/service verification is not claimed as browser verification.

### Explicit limitations and out of scope

Payment Gateway / QRIS: **NOT IMPLEMENTED**.

WhatsApp: **NOT IMPLEMENTED**.

Real RouterOS and `RouterOsV6NetworkDriver`: **NOT IMPLEMENTED**.

Network implementation: `FakeNetworkDriver`.

No scheduler, queue, distributed idempotency key, reconciliation worker, proration,
tax, late fees, or gateway webhook exists yet. Billing enforcement is currently a
manual action/command and is designed for future scheduled invocation. Phase 3 is
not started.

## Phase 2.1 — Billing USP Manual Verification Fixture

### Status

**Phase 2.1 — VERIFIED for fixture consistency and automated workflow tests;
manual browser verification remains NOT TESTED and must be performed by the
operator.**

The original DemoNet seed started Andi Pratama after billing isolation had already
occurred: his connection was suspended, his NetworkAccount was disabled, and no
fresh automation attempt could be produced by the manual enforcement button. The
button correctly processed zero connections because the enforcement policy requires
an overdue, provisioned, currently active connection.

The seed now deliberately starts Andi's stable demo records before enforcement:

```text
Andi Pratama / CL000003 / CON000003
Invoice: overdue, outstanding Rp100000
CustomerConnection: active, no suspension reason
NetworkAccount: active
BillingAutomationAttempt: none for the demo suspension
Billing NetworkOperationLog: none for the demo suspension
```

No audit rows are manufactured by the seeder. The operator can now execute the
real application sequence:

```text
Mark overdue and enforce isolation
  -> processed 1 connection
  -> suspend success / NetworkAccount disabled / connection suspended
Record Rp100000 manual payment
  -> invoice paid
  -> reactivation success / NetworkAccount active / connection active
```

The Customer 360 table displays the safe suspension reason, and invoice detail
displays real BillingAutomationAttempt rows. Network operation logs continue to
display the connection code without exposing secrets. A second enforcement click
is a no-op for the already suspended connection.

The root route now redirects guests to `/login` and authenticated users to
`/dashboard`; the Laravel default welcome page is no longer exposed at `/`.

Added regression coverage verifies seeded state, real enforcement, real payment and
reactivation, absence of pre-seeded suspension audit rows, repeated-enforcement
idempotency, and the root redirect. The fixture reuses Andi through stable name and
relationship lookup in the test; no database IDs are hardcoded in the seeder.

Phase 2 billing rules, multiple-overdue protection, manual-suspension protection,
tenant isolation, and financial/network separation were not weakened. Manual
browser success and failure/retry verification remain **NOT TESTED** until the
operator completes them in a real browser.

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