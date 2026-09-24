# Step 4 Safe Bulk Customer Import Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Extend the existing Discovery customer import review into a safe, complete, preview-only candidate universe for static-IP and Hotspot customers without creating or mutating customer/import state.

**Architecture:** Keep `DiscoveryCustomerImportService` as the single candidate-resolution boundary and have the existing controller render its complete classification result. Reuse the existing read-only Go Engine/OBSERVER capability for authoritative Hotspot account validation, redacting secret fields at the transport boundary. Preserve the existing confirmation service and atomic lifecycle, but make the preview route accept/display only READY selections without invoking confirmation.

**Tech Stack:** Laravel 12, PHP 8.2+, PostgreSQL, Blade, existing Go Engine/OBSERVER monitoring transport, PHPUnit.

**Spec:** User-approved Step 4 Safe Bulk Customer Import requirements in the conversation on September 24, 2026.

## Global Constraints

- Modify the existing Discovery import workflow only; do not create a parallel import system.
- Candidate states are exactly `READY`, `ALREADY ADOPTED`, `DUPLICATE`, `NEEDS VALIDATION`, and `EXCLUDED`.
- Static IP candidates require an individual valid IPv4 `/32` Simple Queue target.
- Aggregate, system, invalid, duplicate, and already-linked resources remain visible as classified candidates rather than disappearing.
- Hotspot candidates require authoritative account validation before `READY`.
- Hotspot identity is router-scoped username; runtime IP/MAC/session values are evidence only.
- Passwords and secrets must never be returned, logged, rendered, or persisted.
- This task is preview-only: no confirmation submission, Customer creation, CustomerConnection creation, lifecycle mutation, observation collection/linking, Discovery rerun, seeder, destructive DB command, commit, or push.
- Preserve future lifecycle `DISCOVERED → Customer → CustomerConnection → ADOPTED + customer_connection_id`; never use `MANAGED`.
- Do not reset or clean unrelated working-tree changes.

---

### Task 1: Establish focused Step 4 test fixtures and candidate-state contract

**Files:**
- Modify: `C:\xampp\htdocs\cosmiclink\tests\Feature\Phase6CNetworkDiscoveryTest.php` or the existing closest discovery-import test file.
- Create if needed: `C:\xampp\htdocs\cosmiclink\tests\Feature\Step4SafeBulkCustomerImportTest.php`.
- Inspect: `C:\xampp\htdocs\cosmiclink\database\migrations\2026_09_24_000048_create_device_observations_table.php`.

**Interfaces:**
- Tests will exercise `DiscoveryCustomerImportService::candidates(int $tenantId): array` and the existing `network.discovery.import.review` route.
- The candidate shape will expose `state`, `access_mode`, `network_mechanism`, `network_identity`, `resource_ids`, and evidence collections without secrets.

- [ ] Write failing tests for complete classification: READY static `/32`, EXCLUDED invalid/aggregate target, ALREADY ADOPTED KLENTRUK, DUPLICATE stable identity, NEEDS VALIDATION Hotspot, and READY validated Hotspot.
- [ ] Write a failing test proving two Hotspot runtime resources with the same username produce one candidate containing both runtime evidence values.
- [ ] Write a failing test proving only READY candidates render selectable controls and the review page renders all summary counters.
- [ ] Write a failing test proving the preview request leaves Customers, CustomerConnections, DeviceObservations, Discovery resources, and management states unchanged.
- [ ] Run the focused tests and record the expected failures before implementation.

### Task 2: Implement safe candidate resolution for static IP and Hotspot

**Files:**
- Modify: `C:\xampp\htdocs\cosmiclink\app\Services\Network\DiscoveryCustomerImportService.php`.
- Inspect/modify only if required by existing interfaces: `C:\xampp\htdocs\cosmiclink\app\Services\Network\GoNetworkMonitoringClient.php`, `C:\xampp\htdocs\cosmiclink\app\Services\Network\NetworkDiscoveryClient.php`, and the corresponding Go monitoring API files.

**Interfaces:**
- `candidates(int $tenantId): array` returns a complete candidate collection and summary metadata.
- Candidate fields use `state`, `access_mode`, `network_mechanism`, `network_identity`, `name`, `router_id`, `resource_ids`, `discovery_evidence`, `hotspot_validation`, and `device_session_evidence`.
- A read-only validator returns sanitized Hotspot account records keyed by normalized username and never includes password/secret fields.

- [ ] Add tests for strict `/32` validation, aggregate/system exclusion, duplicate stable identity detection, and linked/adopted state handling.
- [ ] Add tests for Hotspot account validation, case/whitespace-normalized username grouping, multi-session evidence, and secret redaction.
- [ ] Implement static candidate classification without silently filtering any persisted queue resource in the relevant snapshot.
- [ ] Implement Hotspot grouping by router plus normalized username, retaining runtime IP/MAC/session evidence as non-identity evidence.
- [ ] Reuse persisted authoritative evidence where available; otherwise call only the existing read-only Go Engine/OBSERVER path with the exact allowed RouterOS command `/ip/hotspot/user/print`.
- [ ] Ensure the validator strips password, secret, and credential-like fields before any return, log, or persistence path.
- [ ] Keep duplicate checks read-only and classify candidates rather than throwing during preview.
- [ ] Run the focused service tests and verify they pass.

### Task 3: Update the existing controller and Review/Preview Blade view

**Files:**
- Modify: `C:\xampp\htdocs\cosmiclink\app\Http\Controllers\NetworkDiscoveryController.php`.
- Modify: `C:\xampp\htdocs\cosmiclink\resources\views\network\discovery-import-review.blade.php`.
- Modify only if needed: `C:\xampp\htdocs\cosmiclink\resources\css\app.css`.

**Interfaces:**
- Existing `POST network.discovery.import.review` remains the only route entering review.
- The view receives the complete candidate universe plus summary counts.
- The existing confirm route remains untouched as an eventual lifecycle endpoint and is not submitted during this task.

- [ ] Change review preparation to preserve every classified candidate and reject only malformed request keys, not non-READY candidates.
- [ ] Render prominent counts exactly for STATIC IP READY, HOTSPOT READY, NEEDS VALIDATION, ALREADY ADOPTED, DUPLICATE, EXCLUDED, and TOTAL READY.
- [ ] Render selected/candidate name, access mode, stable identity, mechanism, discovery evidence, Hotspot account validation, device/session evidence, and state.
- [ ] Render checkboxes only for READY candidates and keep non-READY candidates visibly non-selectable.
- [ ] Preserve operator name editing for READY candidates and ensure hidden selection inputs cannot select non-READY candidates.
- [ ] Make the page clearly say Review/Preview and that no import has occurred.
- [ ] Run the focused HTTP/view tests and frontend/build checks.

### Task 4: Verify preview-only behavior and real seeded baseline

**Files:**
- Inspect changed files and the focused tests.
- No production files should be changed solely for verification.

- [ ] Start/use the existing application and navigate to the Discovery Import Review route without clicking Confirm Import.
- [ ] Capture the final Review/Preview screen and verify browser console has zero errors.
- [ ] Report exact summary counts: STATIC IP READY, HOTSPOT READY, NEEDS VALIDATION, ALREADY ADOPTED, DUPLICATE, EXCLUDED, TOTAL READY.
- [ ] Report exact validated Hotspot usernames and prove multi-session grouping is one candidate.
- [ ] Prove KLENTRUK is ALREADY ADOPTED and not selectable.
- [ ] Record the exact RouterOS command executed, or report that no live RouterOS command was needed.
- [ ] Verify Customers = 1, CustomerConnections = 1, DeviceObservations = 100, and Discovery resources = 91 before and after preview.
- [ ] Run focused tests, frontend tests/build, and the broader available suite; report the known unrelated `Phase1ProvisioningTest` failure separately without changing production behavior for it.
- [ ] Review `git diff` and `git status`; do not commit or push.

## Self-review checklist

- All five states are represented and no invalid/aggregate resources disappear from preview.
- Hotspot identity is username-only and grouped per router; IP/MAC are evidence only.
- The only allowed live RouterOS command is `/ip/hotspot/user/print`.
- No secret field can cross the validation boundary or reach Blade/log persistence.
- Review is read-only and confirmation is not invoked.
- Existing future atomic adoption lifecycle and `MANAGED` prohibition remain intact.