---
name: billing-engine
description: Use only when a CosmicLink billing phase is explicitly approved; guide invoice, recurring charge, money, idempotency, and suspension design without implementing billing during Phase 0.
---

# Billing Engine

## Phase gate

This is future-scope guidance. Do not implement invoices, recurring billing,
revenue, payment collection, or automatic suspension in Phase 0.

## Design rules

- Represent money with a dedicated value strategy and fixed currency rules.
- Define invoice and payment state transitions explicitly.
- Make recurring jobs and billing commands idempotent.
- Separate billing decisions from network execution through stable commands/events.
- Audit who or what caused every financial and service-state change.
- Test timezone, period boundaries, retries, duplicate events, and partial failure.