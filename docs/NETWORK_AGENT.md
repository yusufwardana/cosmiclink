# CosmicLink Network Agent — Phase 6E

```text
CosmicLink Cloud (Laravel)
        ↓ HTTPS outbound polling initiated by the agent
CosmicLink Network Agent (Go, inside ISP/private LAN)
        ↓ RouterOS API/API-SSL
MikroTik RouterOS
```

Laravel remains the business brain: it owns tenant identity, agent authorization, router ownership, job creation/status, normalized persistence, resources, snapshots, audits, and operator-visible state. The Go agent only heartbeats, claims work assigned to its tenant identity, performs local read-only discovery, and submits a normalized result.

## Security boundary

- The agent makes outbound HTTP/JSON requests to Laravel; **no public inbound agent port is required**.
- RouterOS API ports do not need public internet exposure. Production requires HTTPS/TLS from agent to Laravel.
- An enrollment token is returned only by server-side enrollment and is stored as a Laravel hash. It is never logged or stored in plaintext in the database.
- Agent jobs have no credential/payload column. On an authorized, atomic `DISCOVER_ROUTER` claim, Laravel decrypts the existing Router credential only in memory and returns it in that authenticated response. The Go agent never persists it or logs it.
- Jobs, agents, routers, and result submission are all checked against the authenticated agent tenant.

## Scope

Phase 6E supports **only** `DISCOVER_ROUTER`. The Go agent reuses the Phase 6D `DiscoveryProvider`; its RouterOS provider remains limited to `/system/resource/print`, `/system/identity/print`, `/ppp/profile/print`, `/ppp/secret/print`, `/ip/pool/print`, and `/queue/simple/print`.

There are zero RouterOS write commands, zero agent mutation job types, no arbitrary command/shell/script input, and no automatic `MANAGED` transition. Successful resources stay `DISCOVERED` until the existing explicit Laravel adoption workflow is used.

## Local agent

Use `network-engine/cmd/agent` with `COSMICLINK_CORE_URL`, `COSMICLINK_AGENT_TOKEN`, optional `COSMICLINK_AGENT_NAME`, and `NETWORK_DISCOVERY_PROVIDER=fake|routeros`. `fake` is the CI/demo/default simulation path. Do not commit a token. The process uses bounded HTTP timeouts, context cancellation, graceful shutdown, and secret-safe structured logs.