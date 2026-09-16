---
name: network-driver
description: Use when designing or implementing CosmicLink network operations, NetworkDriver contracts, fake routers, PPPoE simulation, result DTOs, or operation audit logs.
---

# Network Driver

## Architecture

Business services depend on `NetworkDriver`, never on RouterOS-specific classes.
The Phase 0 implementation is `FakeNetworkDriver`, resolved through the Laravel
container using `NETWORK_DRIVER=fake`.

## Required behavior

- Test connection, create, enable, disable, profile change, and disconnect.
- Return typed operation results with success/failure and safe details.
- Persist every changing command and its outcome in an operation log.
- Support deterministic simulated unavailable-router and rejected-operation cases.
- Sanitize credentials and secrets before logs, exceptions, or responses.
- Enforce router tenant ownership before invoking the driver.

## Prohibitions

No controller may contain MikroTik protocol logic. No fake success may be used
outside explicit simulation mode. Real RouterOS communication is not Phase 0.