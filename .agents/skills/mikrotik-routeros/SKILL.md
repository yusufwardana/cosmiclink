---
name: mikrotik-routeros
description: Use when discussing MikroTik or RouterOS behavior for CosmicLink, especially the RouterOS v6.49.13 deployment target and future API integration.
---

# MikroTik RouterOS

## Current target

The intended hardware target is **RouterOS v6.49.13**. The installed external
`mikrotik-config-gen` skill targets RouterOS v7.x; its v7 guidance is not
authoritative for v6.

## Rules

- Do not assume v7 REST API `/rest/` or `/console/inspect` exists on v6.
- Validate v6 commands against v6 documentation or a v6 test device/CHR.
- Keep protocol/version behavior behind `NetworkDriver`.
- Future real integration should be a dedicated `RouterOsV6NetworkDriver` using
  RouterOS API/API-SSL, with timeouts, errors, and secret redaction tested.
- Phase 0 uses `FakeNetworkDriver`; never connect to a physical router.

## Safety

Never place router credentials in prompts, logs, examples, test output, or
operation payloads. Treat RouterOS commands as version-sensitive instructions.