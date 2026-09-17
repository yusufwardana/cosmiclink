# Go Network Engine — Phase 6B Foundation

## Boundary

```text
Laravel Core → NetworkDriver → GoNetworkDriver → HTTP/JSON → Go Network Engine → NetworkProvider → FakeProvider
```

Laravel remains the business brain. It authorizes actions, owns tenants, customers, billing, customer lifecycle decisions, `NetworkAccount` persistence, and `NetworkOperationLog` audit records. Go only executes an already-authorized network command and returns a normalized execution result. Go does not evaluate invoices, payments, suspensions, provisioning eligibility, authorization, or outages.

`FakeNetworkDriver` remains the permanent pure-Laravel simulation layer (`NETWORK_DRIVER=fake`). `NETWORK_DRIVER=go` selects Laravel's `GoNetworkDriver`; the Go service uses `FakeProvider` during Phase 6B. They are intentionally separate layers.

## Local startup

Install Go 1.22 or newer, then run the engine locally:

```powershell
Set-Location c:\xampp\htdocs\cosmiclink\network-engine
$env:GO_NETWORK_ENGINE_TOKEN = 'local-development-token'
go run .\cmd\server
```

The default listener is `127.0.0.1:8787`. The listener is intentionally local by default. Set `GO_NETWORK_ENGINE_ADDRESS` to override it.

Configure Laravel locally, never committing a real token:

```dotenv
NETWORK_DRIVER=go
GO_NETWORK_ENGINE_URL=http://127.0.0.1:8787
GO_NETWORK_ENGINE_TOKEN=local-development-token
GO_NETWORK_ENGINE_CONNECT_TIMEOUT_SECONDS=2
GO_NETWORK_ENGINE_TIMEOUT_SECONDS=10
```

## HTTP contract

`GET /health` is unauthenticated and returns `{"status":"ok"}`.

Protected routes require `Authorization: Bearer <GO_NETWORK_ENGINE_TOKEN>`:

| Laravel operation | Route |
| --- | --- |
| `TEST_CONNECTION` | `POST /v1/network/routers/test` |
| `CREATE_PPPOE` | `POST /v1/network/accounts` |
| `ENABLE_PPPOE` | `POST /v1/network/accounts/{reference}/enable` |
| `DISABLE_PPPOE` | `POST /v1/network/accounts/{reference}/disable` |
| `CHANGE_PROFILE` | `POST /v1/network/accounts/{reference}/profile` |
| `DISCONNECT_SESSION` | `POST /v1/network/accounts/{reference}/disconnect` |

Phase 6C adds a distinct authenticated read-only route:

| Capability | Route |
| --- | --- |
| `DISCOVERY` | `POST /v1/discovery/routers/{router_ref}` |

The discovery request is only `tenant_ref` and matching `router_ref`. Its normalized response is `success`, `provider`, `router_ref`, `discovered_at`, code/message, and snapshot sections `device`, `profiles`, `accounts`, `address_pools`, and `queues`. It is handled only by `DiscoveryProvider`, not `NetworkProvider`, and has no mutation methods.

Each request contains only execution context: `operation_id`, `idempotency_key`, `operation`, `tenant_ref`, `router_ref`, optional `account_ref`, and operation `parameters`. Create-account parameters include PPPoE `username`, `profile`, and password where Laravel's existing driver contract requires it. The Go engine never writes it to logs or returns it in a response.

Responses are normalized, including failures:

```json
{
  "success": true,
  "operation_id": "...",
  "provider": "fake",
  "code": "ACCOUNT_DISABLED",
  "message": "Network account disabled",
  "data": {}
}
```

## Security, timeouts, and logging

- Mutation routes compare bearer tokens with `crypto/subtle.ConstantTimeCompare`; health is intentionally unauthenticated.
- Requests are JSON-only, reject unknown fields, and are limited to 64 KiB.
- Laravel has explicit connection (2 seconds by default) and overall request (10 seconds by default) limits. Go uses header/read/write/idle limits of 5/10/15/60 seconds.
- Go structured logs include operation references, provider, result, and duration. They intentionally exclude request parameters, tokens, router credentials, and PPPoE passwords.
- Laravel sanitizes returned Go diagnostics before `NetworkOperationService` persists its existing business audit log.

## Idempotency and FakeProvider

Mutation results are held in a mutex-protected, in-memory idempotency map keyed by `idempotency_key`. A repeated key receives the original normalized result without provider re-execution. The map is lost when the process restarts, is local to one server instance, has no TTL/size cap, and is not a distributed production mechanism. Laravel retains its own business-operation safety.

`FakeProvider` has deterministic process-local account state. It supports connection testing, create, enable, disable, profile changes, and disconnects; repeated enable/disable returns deterministic already-enabled/already-disabled success. It does not persist across restart and does not communicate with any device.

## Phase 6B limitations

- **RouterOS/MikroTik is not implemented.** There are no RouterOS libraries, protocols, REST calls, SNMP, ICMP, device credentials, polling, discovery, or real provisioning.
- No gRPC, telemetry, websockets, topology, inventory, or technician workflow is included.
- The idempotency cache is development-foundation only and must be replaced or backed by durable shared storage before horizontally scaled production execution.

## Phase 6C discovery limitation

`FakeDiscoveryProvider` is deterministic simulated data only. No RouterOS/MikroTik connection, API, REST request, queue/profile mutation, OLT, AP, CPE, SNMP, ICMP, agent, or private-LAN access is implemented. See `docs/SAFE_ISP_MIGRATION.md` for the explicit read-only adoption boundary.