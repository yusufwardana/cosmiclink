---
name: testing
description: Use when creating or reviewing CosmicLink tests, choosing test boundaries, validating tenant isolation, testing fake network operations, or verifying a phase.
---

# Testing

## Default workflow

Use TDD: write one focused failing test, run it and confirm the expected
failure, implement the minimum behavior, run it green, then refactor safely.

## Coverage priorities

- Authentication and protected routes.
- Cross-tenant read, update, delete, and operation rejection.
- Validation, mass-assignment, encrypted credentials, and secret redaction.
- FakeNetworkDriver success and deterministic failure paths.
- Operation logs for both success and failure.
- Database foreign keys, indexes, uniqueness, and state transitions.

## Completion

Run focused tests first, then the complete available suite and required static
checks. Record exact commands and results. Do not replace real behavior with
tests that only assert mock calls.