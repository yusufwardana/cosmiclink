---
name: phase-verification
description: Use when checking whether a CosmicLink implementation phase is complete, especially Phase 0, and when preparing a verified completion report.
---

# Phase Verification

## Phase 0 definition of done

Verify that the application runs, authenticates users, isolates tenants, manages
routers, encrypts credentials, resolves `FakeNetworkDriver`, simulates PPPoE
operations, records successful and failed operation logs, displays simulation
mode, and passes the full automated suite.

## Required report

State `VERIFIED`, `PARTIAL`, or `FAILED`. List files, migrations, security
controls, exact test results, manual checks, known limitations, technical debt,
and the recommended next phase. Distinguish unavailable checks from passing
checks.

## Scope gate

Do not mark Phase 0 complete if billing, QRIS, WhatsApp, ticketing, inventory,
or real RouterOS communication was silently introduced or if cross-tenant
operations are not tested and rejected.