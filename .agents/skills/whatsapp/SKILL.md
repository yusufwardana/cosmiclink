---
name: whatsapp
description: Use only when a CosmicLink WhatsApp notification phase is explicitly approved; guide provider abstraction, consent, retry, and secret handling without implementing WhatsApp during Phase 0.
---

# WhatsApp Notifications

## Phase gate

Do not implement WhatsApp providers, message sending, templates, or automated
notifications in Phase 0.

## Design rules

- Require tenant-aware recipient consent and an auditable message purpose.
- Hide provider credentials and redact them from logs and exceptions.
- Define a provider-neutral message contract and delivery status lifecycle.
- Make retries bounded, observable, and idempotent where provider semantics allow.
- Protect personal data and avoid placing secrets in message content.