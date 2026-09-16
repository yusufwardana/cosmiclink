---
name: database-design
description: Use when designing CosmicLink tables, migrations, relationships, indexes, constraints, statuses, JSON metadata, or data lifecycle changes.
---

# Database Design

## Rules

- Model ownership explicitly with foreign keys and indexed `tenant_id` columns.
- Make uniqueness match the business boundary, commonly `(tenant_id, key)` or
  `(tenant_id, router_id, username)`.
- Use timestamps and explicit status transitions for operational state.
- Choose nullable fields deliberately and document encrypted or JSON values.
- Never store router passwords or provider secrets as plaintext.
- Make migrations reversible where practical and safe for existing data.

## Review checklist

- Foreign keys, delete behavior, indexes, and uniqueness are deliberate.
- Constraints support the authorization model rather than replace it.
- Payload columns cannot become an accidental secret store.
- Tests cover important database constraints and state transitions.