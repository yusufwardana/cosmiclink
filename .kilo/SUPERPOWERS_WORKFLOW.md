# CosmicLink Superpowers Workflow

This file is a project-local workflow adapter inspired by
[`obra/superpowers`](https://github.com/obra/superpowers). It is intentionally
small and dependency-free. It does not copy the upstream repository and does
not provide a Laravel application.

## Non-negotiable working rules

1. **Understand before changing.** Inspect the repository, framework version,
   conventions, relevant files, configuration, and tests before proposing or
   making changes. Never assume the repository is empty or that a familiar
   stack is in use.
2. **Design before implementation.** For new features or architectural work,
   clarify the goal, constraints, interfaces, failure modes, and acceptance
   criteria. Present the design/plan and wait for approval when the user has
   not already approved implementation.
3. **Use the smallest sound change.** Prefer existing patterns. Apply YAGNI;
   do not add speculative modules, dependencies, abstractions, or refactors.
4. **Test-driven development.** For production behavior, write a focused
   failing test first, run it and confirm the failure is caused by the missing
   behavior, implement the minimum code, then run the test again. Refactor only
   while the tests remain green. Configuration-only or explicitly throwaway
   work is exempt, but the exception must be stated.
5. **Debug systematically.** Reproduce the problem, identify the root cause,
   test the hypothesis, implement a targeted fix, and add a regression test.
   Do not mask errors or make unrelated changes.
6. **Verify before completion.** Inspect every changed file, run the narrowest
   relevant tests, then run the full available suite and required build/lint
   checks. Report exact commands and results. Never claim success from an
   unrun or unavailable check.
7. **Protect secrets and boundaries.** Do not expose credentials, tokens,
   private data, or unsafe debug output. Preserve authentication,
   authorization, tenant isolation, validation, and CSRF protections.
8. **Keep scope explicit.** If a task grows beyond its approved scope, stop,
   explain the new boundary, and update the plan before continuing.

## Standard task flow

### A. Classify the work

- **Spike:** answer a feasibility question cheaply; throwaway artifacts must
  remain clearly labeled.
- **Bounded change:** modify an existing, understood flow; give a short design
  and acceptance criteria.
- **Architectural change:** create or restructure a subsystem; produce a
  written design and implementation plan before coding.

When uncertain, use the heavier classification.

### B. Plan

The plan must identify:

- exact absolute file paths to create or modify;
- interfaces and dependencies between tasks;
- database/configuration/security implications;
- focused tests and expected red/green results;
- commands for validation;
- known limitations and out-of-scope items.

Break work into independently testable tasks. Do not use placeholder steps
such as “add appropriate validation” or “write tests later”.

### C. Implement and review

Implement one small task at a time. After each task:

- review the diff for accidental changes and secret leakage;
- run the relevant checks;
- check behavior against the approved requirements;
- preserve a clean rollback boundary where possible.

Before delivery, perform a self-review for correctness, security, scope,
maintainability, and documentation. Summarize what was verified and what could
not be verified.

## CosmicLink-specific baseline

The active product objective is **Phase 0 — Foundation + Network Simulation**.
Unless the user explicitly changes scope, do not implement billing, QRIS,
WhatsApp, ticketing, inventory, real RouterOS communication, or later SaaS
modules. For the Phase 0 plan, honor the existing requirements for Laravel
compatibility, tenant isolation, encrypted router credentials, the
`NetworkDriver` abstraction, deterministic fake network operations, audit logs,
and automated tests.

Because the workspace currently has no Laravel source files, the first product
task must be a repository/project baseline decision, not an assumption-driven
implementation.

### Router target and skill compatibility

The current intended hardware target is **MikroTik RouterOS v6.49.13**.

The installed `mikrotik-config-gen` skill from `EvilFreelancer/rpa-skills`
targets **RouterOS v7.x** and does not cover RouterOS v6.49.13. Treat its
v7-specific guidance as non-authoritative for v6, especially guidance involving
REST API `/rest/`, `/console/inspect`, v7-only command paths, v7 scripting
behavior, or v7-only features. Validate any future v6 command against RouterOS
v6 documentation or a v6 test device/CHR before implementation.

Phase 0 must continue to use `FakeNetworkDriver` with:

```text
NETWORK_DRIVER=fake
```

No real RouterOS connection is implemented. A future real integration should use a dedicated
`RouterOsV6NetworkDriver` based on the RouterOS API service (API/API-SSL), with
version-specific behavior isolated behind `NetworkDriver`. Do not introduce a
v6 implementation until its protocol, authentication, timeout, error, and
secret-redaction tests are defined.

## Completion report

Every completed task should report:

1. status: VERIFIED, PARTIAL, or FAILED;
2. files created/modified;
3. tests/checks run with exact results;
4. manual verification, if any;
5. known limitations and technical debt;
6. recommended next step.

## Upstream attribution

This adapter is based on the public workflow concepts in Superpowers version
`6.3.0` metadata observed on 2026-09-16. The upstream project is MIT licensed:
<https://github.com/obra/superpowers>. Review the upstream project before
updating this adapter; do not represent this file as an official plugin.