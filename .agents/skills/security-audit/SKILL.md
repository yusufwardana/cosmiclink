---
name: security-audit
description: Use when reviewing CosmicLink code, routes, models, operations, integrations, secrets, authentication, authorization, or tenant isolation for security defects.
---

# Security Audit

## Checklist

- Authentication protects all private routes and logout invalidates the session.
- Policies/server-side authorization protect every read, write, delete, and command.
- Tenant ownership is derived from trusted context and checked in queries/actions.
- CSRF and server-side validation are enabled for browser mutations.
- Mass assignment, IDOR, unsafe redirects, and overbroad data exposure are tested.
- Router/provider credentials are encrypted and never returned or logged.
- Exceptions and operation logs redact passwords, tokens, headers, and secrets.
- Webhooks verify signatures and are idempotent before state changes.
- Failure paths do not silently report success or swallow actionable errors.

## Output

Report severity, affected path, exploit condition, evidence, remediation, and
verification test. Do not claim a clean audit without running the checks.