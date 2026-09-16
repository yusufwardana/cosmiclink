---
name: cosmiclink-architecture
description: Use when designing or reviewing CosmicLink architecture, module boundaries, service boundaries, dependencies, or phase scope.
---

# CosmicLink Architecture

## Purpose

Keep CosmicLink modular, deployable on an ordinary VPS, and safe to extend.

## Rules

- Controllers stay thin; business behavior belongs in actions/services.
- Domain modules depend on stable contracts, not vendor implementations.
- Keep tenant ownership and authorization server-side.
- Prefer Laravel-native solutions and existing project conventions.
- Apply YAGNI: do not build future modules before their phase.
- Phase 0 means foundation and network simulation only.

## Review checklist

- Identify the owning module and its public interface.
- Trace dependencies inward toward domain contracts.
- Check authentication, authorization, tenant scope, and secret handling.
- Define failure behavior and observable audit information.
- List tests and documentation required before completion.

## Out of scope

Do not add billing, payments, WhatsApp, ticketing, inventory, or real RouterOS
communication to a Phase 0 change.