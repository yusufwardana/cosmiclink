---
name: multi-tenancy
description: Use when designing or implementing tenant ownership, scoped queries, policies, authorization, or cross-tenant security in CosmicLink.
---

# Multi-Tenancy

## Rules

- Every business-owned record has a `tenant_id` where appropriate.
- Derive tenant context from the authenticated user or trusted application
  context, never from an untrusted hidden field.
- Verify ownership before read, update, delete, or operational commands.
- Use foreign keys and tenant-aware indexes/unique constraints.
- Never accept a client-supplied `user_id` or `tenant_id` as authority.
- Use policies or equivalent server-side authorization for every resource.

## Required tests

For each tenant-owned resource test that Tenant A cannot view, update, delete,
or operate Tenant B's record. Include direct URL/ID manipulation and command
execution paths, not only normal UI flows.

## Review questions

- Where is tenant context established?
- Can any query omit the tenant constraint?
- Are jobs, scheduled tasks, exports, and logs tenant-aware?
- Do foreign keys prevent orphaned cross-tenant references?