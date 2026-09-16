---
name: payment-webhook
description: Use only when a CosmicLink payment integration phase is explicitly approved; guide secure webhook design without implementing payment gateways during Phase 0.
---

# Payment Webhooks

## Phase gate

Do not implement QRIS, payment gateways, webhook routes, or payment settlement
in Phase 0.

## Design rules

- Verify provider signatures before parsing business events.
- Store provider event IDs and enforce idempotency.
- Reject replayed, malformed, expired, or unauthorized requests safely.
- Keep raw payloads free of unnecessary secrets and sensitive data.
- Use transactions for settlement state and emit follow-up actions safely.
- Test duplicate delivery, out-of-order delivery, invalid signatures, and retries.