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

The operator subsequently manually verified the Phase 2.1 FakeNetworkDriver
workflow in the browser: overdue invoice, successful `DISABLE_PPPOE`, suspended
connection, manual payment `MANUAL-ANDI-001`, paid invoice, successful
`ENABLE_PPPOE`, and active connection with cleared suspension reason. This does
not verify real RouterOS hardware.

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

## Phase 3 — Payment Automation + WhatsApp-First Operations

### Provider architecture

Phase 3 adds provider-neutral contracts selected through configuration:

```text
Billing -> PaymentGateway -> FakePaymentGateway
Messaging -> MessagingProvider -> FakeMessagingProvider
```

The default development configuration is:

```dotenv
PAYMENT_GATEWAY=fake
MESSAGING_PROVIDER=fake
```

Unknown providers fail closed in the service container. No provider credentials,
real payment gateway, QRIS transaction, or WhatsApp vendor integration exists.

### Payment requests and event processing

`PaymentRequest` represents a provider-independent pending payment request with
integer IDR amount, invoice/customer references, provider reference, status,
expiry, simulated payment representation, and metadata. The fake gateway reuses
an existing pending request for the same invoice and produces deterministic
development events.

`ProcessPaymentProviderEvent` validates provider/reference, tenant ownership,
amount, currency, event identity, and event type before delegating settlement to
the existing `RecordPayment` action. It never updates invoices directly and never
calls the network driver. Duplicate provider events are idempotent, unknown
references and amount mismatches fail safely, and provider payload persistence is
sanitized. `PaymentProviderEvent` provides durable event idempotency/audit storage.

The UI labels the fake request and payment representation **SIMULATED PAYMENT**;
it is not a real QRIS payload or payable transaction.

### Messaging and phone normalization

`MessagingProvider`, `FakeMessagingProvider`, `MessageLog`, and
`PhoneNormalizer` provide a provider-independent WhatsApp-style notification
foundation. Indonesian local numbers such as `081234567890` normalize to
`+6281234567890` in one boundary. Invalid numbers produce a safe skipped message
log; the fake provider can deterministically fail delivery for development tests.

Provider-neutral templates currently include:

```text
invoice_created
payment_reminder
payment_received
service_suspended
service_reactivated
```

Invoice generation, payment settlement, successful isolation, and successful
reactivation create message attempts after their primary state transitions.
Message failures are recorded separately and do not roll back financial or
network state. The Messages page is tenant-scoped and visibly identifies fake
delivery as simulation mode.

### Phase 3 verification

- Phase 2.1 baseline/freeze: **VERIFIED**, checkpoint commit
  `0b9984f` (`CosmicLink Phase 2.1 verified billing automation baseline`).
- Phase 2.1 manual billing workflow: **VERIFIED** by operator for
  FakeNetworkDriver; real RouterOS remains NOT IMPLEMENTED.
- Phase 3 focused provider/messaging tests: **VERIFIED**, 8 tests / 21 assertions.
- Complete suite after Phase 3 changes: recorded by the final verification run.
- Payment request, event, message, and tenant checks are automated; no provider
  secret is rendered or stored in sanitized event payloads.
- No real payment, QRIS, WhatsApp, RouterOS, monitoring, outage, or Phase 4
  integration is implemented.

### Phase 3 demo workflow

After a fresh seed, Siti Rahma has an unpaid invoice and a pending fake payment
request with no provider event. The operator can open the request, confirm the
SIMULATED PAYMENT label, invoke the simulated success action, and observe the
provider event, Payment, paid invoice, existing reactivation behavior where
applicable, and message history. Seed data does not pre-create the settlement
event or payment for this scenario.

### Phase 3 limitations and out of scope

- `FakePaymentGateway` and `FakeMessagingProvider` are the only implementations;
  real providers and credentials are **NOT IMPLEMENTED**.
- No public webhook endpoint or cryptographic provider signature verifier is added;
  the provider-independent processing action is the safe internal boundary for
  the fake simulation path.
- No scheduler, queue, retry worker, template designer, QRIS, WhatsApp vendor,
  payment gateway, RouterOS, monitoring, outage intelligence, tickets,
  technicians, inventory, portal, AI, or Phase 4 work exists.

## Phase 3.1 — Reminder Automation + End-to-End USP Verification

### Reminder architecture and eligibility

`SendPaymentReminder` is the dedicated reminder action. It validates tenant
ownership, requires an unpaid or overdue invoice with an outstanding balance, and
does nothing for paid or cancelled invoices. It delegates recipient normalization,
template rendering, provider delivery, and MessageLog persistence to the existing
messaging boundary. No controller calls `FakeMessagingProvider` directly.

Reminder idempotency is durable: one `payment_reminder` MessageLog is allowed per
invoice, enforced by a unique `message_logs.idempotency_key` containing tenant,
invoice, and template. A repeated manual reminder action returns the existing sent,
skipped, or failed attempt rather than sending uncontrolled duplicate reminders.
Missing or invalid phone numbers create an auditable `skipped` MessageLog with
`INVALID_PHONE`; provider failure creates `failed`. Neither result mutates invoice,
payment, connection, or NetworkAccount state. Bulk reminders are intentionally
omitted for this phase; manual invocation is sufficient and no scheduler or queue
worker is introduced.

### Complete fake-provider chains

The combined regression tests now verify:

```text
Overdue
  -> ProcessOverdueBilling
  -> DISABLE_PPPOE
  -> billing_overdue suspension
  -> service_suspended MessageLog

PaymentRequest
  -> FakePaymentGateway success event
  -> ProcessPaymentProviderEvent
  -> RecordPayment
  -> Invoice PAID
  -> ReactivateCustomerConnection
  -> ENABLE_PPPOE
  -> payment_received + service_reactivated MessageLog
```

Financial and network state remain authoritative over messaging delivery. A fake
messaging failure is recorded separately and cannot roll back a payment or network
transition.

### Customer 360 and UI

Customer detail now includes compact billing, recent payments, and recent message
sections alongside connections and connection-traced network operations. Invoice
detail provides `Send Payment Reminder`, simulated payment-request generation,
manual payment entry, payment request history, payment history, and billing
automation history. The Messages page is tenant-scoped and visibly marked
`SIMULATION MODE`.

### Demo and manual verification instructions

After `php artisan migrate:fresh --seed`, Siti Rahma has an unpaid invoice with a
valid Indonesian phone, a pending `PAY-DEMO-SEED-001` fake payment request, no
provider event, and no payment. The operator can manually verify:

```text
Billing / Invoices -> Siti invoice -> Send Payment Reminder
  -> payment_reminder MessageLog / sent

Invoice -> Generate Simulated Payment Request
  -> SIMULATED PAYMENT -> Simulate Successful Payment
  -> provider event -> Payment -> Invoice PAID

Customers -> Siti Rahma
  -> Billing / Payments / Messages / Network Operations
```

For the combined overdue-to-reactivation workflow, use the existing Andi fixture:
the operator can enforce overdue isolation, inspect the real suspend attempt and
`DISABLE_PPPOE`, record a full payment, then inspect the real reactivation attempt,
`ENABLE_PPPOE`, `payment_received`, and `service_reactivated` records. These are
manual browser instructions; this agent run did not claim browser execution.

### Phase 3.1 verification and limitations

- Phase 2.1 manual FakeNetworkDriver workflow remains **VERIFIED** by the operator.
- Phase 3.1 focused automation tests: **VERIFIED**, 15 tests / 49 assertions.
- The full suite currently reports 49 tests / 192 assertions.
- Public payment webhook: **NOT IMPLEMENTED**.
- Signature verification and real provider authenticity: **NOT IMPLEMENTED**.
- Real WhatsApp, QRIS, payment gateways, RouterOS, monitoring, and Phase 4:
  **NOT IMPLEMENTED**.
- Browser/runtime verification: **VERIFIED** with Playwright MCP and Chromium for
  Testing 143.0.7499.4 (`playwright-core` 1.57.0; Chromium revision 1200).
- Siti Rahma browser evidence: valid phone, unpaid invoice, pending
  `PAY-DEMO-SEED-001`, `payment_reminder` sent, repeated reminder returned the
  existing durable attempt, simulated payment produced a paid PaymentRequest,
  PaymentProviderEvent, exactly one Payment, PAID invoice, zero outstanding, and
  `payment_received` sent.
- Andi Pratama browser evidence: overdue/active precondition, Smart Auto-Isolation,
  successful `DISABLE_PPPOE`, `service_suspended` sent, full payment, automatic
  reactivation, successful `ENABLE_PPPOE`, `service_reactivated` sent, and final
  active connection/network account state.
- Customer 360 browser evidence: connections, billing, recent payments, recent
  messages, and recent network operations rendered for Siti without visible
  cross-customer leakage.
- Final regression after browser verification: **49 passed / 193 assertions**.
- Manual browser verification: **VERIFIED**; all required Phase 3.1 browser flows
  passed. Real providers and integrations remain **NOT IMPLEMENTED**.

## Phase 4A — Monitoring Foundation + Network Health Simulation

### Monitoring architecture

Phase 4A adds a provider-neutral `MonitoringDriver` contract, separate from the
existing `NetworkDriver`. `MonitoringDriver` only observes health; `NetworkDriver`
continues to mutate PPPoE/network state. `MonitoringService` owns tenant-scoped
observation orchestration and persists typed `HealthObservationResult` values as
`HealthObservation` records.

`FakeMonitoringDriver` is the deterministic implementation. It supports router
`online`, `degraded`, and `offline`, plus connection `online`, `offline`, and
`unknown`. Metrics are intentionally small: reachability/online state, latency,
packet loss, observed time, and a simulation marker. It never changes state
randomly and does not call `NetworkOperationService`.

### Persistence and current health

`health_observations` stores tenant, constrained subject type (`router` or
`connection`), subject ID, health state, metrics, provider, metadata, and observed
time. Indexes cover tenant/subject/time and tenant/state. History is append-only;
the latest observation is selected per subject for the operator view. The data can
answer which router and customer connection was observed at a timestamp and when
it recovered, without implementing outage incidents or correlation.

### Simulation and safety

Development simulation state is stored on routers and customer connections. The
controls are exposed only when `MONITORING_DRIVER=fake` and `APP_ENV` is `local` or
`demo`; unsupported monitoring drivers fail closed and do not expose fake controls.
The Monitoring page clearly states `SIMULATION MODE` and that no physical router
is monitored. Monitoring failures/observations do not mutate invoices, payments,
customer lifecycle status, network account status, network operation logs, or
message logs. An active connection may therefore be observed `offline` while its
administrative status remains `active`.

### Operator UI and Customer 360

The authenticated, tenant-scoped Monitoring page provides router and connection
tables, current state, metrics, last checked time, manual observation, simulation
controls, a tenant-scoped bulk `Run Monitoring Check`, and compact history. Customer
360 now displays Network Health and Last Checked beside each connection while
retaining billing, payment, message, and network-operation sections.

### Verification and limitations

Phase 4A automated coverage: **VERIFIED**, 7 focused tests / 26 assertions; full
regression after implementation: **56 passed / 219 assertions**. Browser automation
verified login, Monitoring baseline, healthy observations, simulated router and
connection outage, active lifecycle preservation, Customer 360 offline health,
recovery to online, and retained history (`ONLINE` → `OFFLINE` → `ONLINE`).

Fake monitoring: **VERIFIED**.

Real RouterOS monitoring: **NOT IMPLEMENTED**.
SNMP: **NOT IMPLEMENTED**.
Real ICMP monitoring: **NOT IMPLEMENTED**.
Automatic polling: **NOT IMPLEMENTED**.
Scheduler: **NOT IMPLEMENTED**.
Outage correlation: **NOT IMPLEMENTED**.
Outage incidents: **NOT IMPLEMENTED**.
Automatic outage notifications: **NOT IMPLEMENTED**.
Phase 4B: **NOT STARTED**.

## Phase 4B — Outage Intelligence

### Architecture and correlation

Phase 4B adds tenant-safe `OutageIncident` records and the
`outage_affected_connections` evidence table. `OutageCorrelationService` runs
after `MonitoringService` persists the normal `HealthObservation` records. It
never runs inside `FakeMonitoringDriver`, and it never calls `NetworkDriver` or
`NetworkOperationService`.

The deterministic rules are configurable through `OUTAGE_MINIMUM_CONNECTIONS`
(default `3`) and `OUTAGE_WINDOW_MINUTES` (default `10`). Only active,
provisioned connections are eligible. Offline observations must belong to the
same tenant, same router, and current observation window. One detected or
acknowledged incident is reused per router/outage; repeated monitoring updates
its correlation count and affected connection set rather than duplicating it.
Different routers produce different incidents. A later outage after resolution
may create a new incident.

### Incident lifecycle and recovery

The lifecycle is:

```text
detected -> acknowledged -> resolved
```

Acknowledgement records `acknowledged_at` and changes only incident state. When
all connections attached to the active incident have a current online
observation, the incident is marked `resolved` with `resolved_at`; affected
connections and all historical observations remain available. The incident
stores router, correlation count, evidence window, timestamps, and affected
connection/customer references.

### UI and tenant safety

The Monitoring page now includes a compact `OUTAGE INCIDENTS` section with
status, router, detection time, affected customer/connection counts, resolved
time, details, and `Acknowledge Incident`. The incident detail page lists the
affected customers and connections and explicitly states that no billing or
network enforcement is performed. Customer 360 shows recent active/resolved
outage incidents beside health, billing, payments, messages, and operations.
All incident views and acknowledgement actions use existing authentication,
policies, CSRF, and tenant ownership checks.

### Phase 4B verification

Automated outage coverage is **VERIFIED**. The focused monitoring suite reports
11 tests / 38 assertions, and the complete suite reports **60 passed / 231
assertions**. Tests cover below-threshold behavior, shared-router correlation,
different-router separation, affected tracking, deduplication, acknowledgement,
recovery, tenant isolation, lifecycle preservation, and no billing/network
side effects.

Browser automation with the local deterministic fake driver verified:

```text
healthy -> no incident
3 shared-router connections offline -> exactly ONE incident
affected customers visible -> acknowledge
repeat check -> no duplicate
connections online -> RESOLVED
Customer 360 outage context -> visible
health history -> retained
```

Fake outage intelligence: **VERIFIED**.

Automatic outage notifications: **NOT IMPLEMENTED**.
Ticketing/technicians: **NOT IMPLEMENTED**.
Topology/network map: **NOT IMPLEMENTED**.
Scheduler/queues/WebSockets: **NOT IMPLEMENTED**.
Real RouterOS/SNMP/ICMP monitoring: **NOT IMPLEMENTED**.
Phase 4C or later work: **NOT STARTED**.