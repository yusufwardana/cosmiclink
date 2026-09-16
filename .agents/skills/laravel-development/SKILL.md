---
name: laravel-development
description: Use when implementing or reviewing Laravel models, migrations, controllers, requests, policies, services, routes, queues, scheduling, or tests in CosmicLink.
---

# Laravel Development

## Workflow

1. Audit the actual Laravel/PHP version and existing conventions.
2. Write a focused failing feature or unit test.
3. Implement the smallest Laravel-native change.
4. Run the focused test, then the full available suite.
5. Inspect the diff, migrations, authorization, logs, and documentation.

## Rules

- Use Form Requests for complex validation and Policies for resource access.
- Protect mass assignment explicitly with `$fillable` or guarded design.
- Use transactions for multi-record state changes.
- Do not place network, billing, or notification integrations in models.
- Queue slow or retryable work only after defining idempotency and failure state.
- Never assume framework features without checking the installed version.

## Baseline warning

The CosmicLink workspace currently has no Laravel source. Do not scaffold or
claim Laravel behavior until `artisan` and `composer.json` are present.