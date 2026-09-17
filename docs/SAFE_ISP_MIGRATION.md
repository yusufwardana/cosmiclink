# Safe ISP Migration — Phases 6C–6D

## Scope

Phase 6C provides the safe foundation for onboarding an existing ISP network:

```text
CONNECT → DISCOVER → COMPARE → IMPORT / ADOPT → MANAGE (future)
```

Only **DISCOVER**, **COMPARE**, and explicit local **ADOPT** are implemented. **MANAGE is not enabled.** No Phase 6C route changes a router, PPPoE account, profile, queue, pool, firewall, or session.

## Boundaries

Laravel is the control plane. It owns tenant authorization, routers, snapshots, resource records, customer-connection mappings, reconciliation, adoption decisions, and audit evidence.

The Go Network Engine is a provider-neutral read-only transport/execution boundary. The existing Phase 6B `NetworkProvider` mutation contract remains unchanged. Phase 6C adds a segregated `DiscoveryProvider` used only by `POST /v1/discovery/routers/{router_ref}`.

The browser calls Laravel only. Laravel calls Go through `GoNetworkDiscoveryClient`; it shares URL, bearer token, and timeout configuration with the Phase 6B Go driver but does not reuse its mutation methods.

## Discovery providers

`NETWORK_DISCOVERY_PROVIDER` is explicit and is never inferred from an environment name:

- `fake` (default): `FakeDiscoveryProvider` returns deterministic simulated data for `CORE-01`, PPPoE profiles `10M`, `20M`, `50M`, PPPoE accounts `existing-user-001` through `existing-user-003`, `pppoe-pool`, and a simulated relevant queue.
- `routeros`: `RouterOSDiscoveryProvider` connects using the RouterOS binary API or API-SSL, then performs fixed, read-only inventory discovery.

The Phase 6D route is:

```text
Browser → authenticated Laravel action → GoNetworkDiscoveryClient
        → Go DiscoveryProvider → RouterOSDiscoveryProvider
        → RouterOS API / API-SSL → MikroTik
```

Laravel remains provider-neutral. RouterOS library types and raw RouterOS rows do not leave Go.

## RouterOS v6 read-only boundary

The intended and documented target is RouterOS **v6.49.13**. Software tests cover normalized v6-style API responses, including the post-6.43 API login behavior supported by the selected client. A router reporting a version outside `6.49.x` receives a capability warning; discovery remains read-only and is not certified for that version.

Phase 6D uses `github.com/go-routeros/routeros/v3` **v3.0.1**, an MIT-licensed pure-Go RouterOS API client. It was selected because it has context-aware API/API-SSL dialing and sentence execution, handles pre- and post-6.43 login flows, and stays entirely behind `RouterOSTransport`. No Laravel code knows or imports this dependency.

The production transport has no application-facing generic execute API. The provider may request exactly this command allowlist:

```text
/system/resource/print
/system/identity/print
/ppp/profile/print
/ppp/secret/print
/ip/pool/print
/queue/simple/print
```

Every requested read is checked against the allowlist before it reaches the transport. Commands such as `add`, `set`, `remove`, `enable`, `disable`, `reset`, `move`, `comment`, and `make-static` are not represented by provider methods and are rejected by the guard before transmission. RouterOS mutation is **not implemented in Phase 6D**.

Normalized inventory is deliberately small:

- device: identity, RouterOS version, architecture, board/platform, uptime, read-only mode, and capability warning where applicable;
- PPPoE profiles: provider ID, name, local/remote address or pool reference, rate limit;
- PPPoE accounts: provider ID, username, profile, service, enabled state, comment;
- address pools: provider ID, name, ranges;
- simple queues: provider ID, name, target, max limit.

`/ppp/secret/print` may contain a PPPoE password, but the normalizer never maps it. It is discarded before `DiscoveryResult`, response, fingerprinting, snapshot persistence, reconciliation, audit, or UI rendering.

## RouterOS connection and credentials

Router host, API port, username, and encrypted password remain on the Laravel `Router` model. The password is Laravel-`Crypt` encrypted at rest and hidden from model serialization. When `NETWORK_DISCOVERY_PROVIDER=routeros`, Laravel decrypts it only to construct the authenticated Laravel-to-Go request body; the browser never receives it and Go never returns or logs it.

Connection configuration is explicit:

```dotenv
NETWORK_DISCOVERY_PROVIDER=routeros
ROUTEROS_DISCOVERY_TRANSPORT=api_ssl
ROUTEROS_DISCOVERY_CONNECT_TIMEOUT_SECONDS=3
ROUTEROS_DISCOVERY_READ_TIMEOUT_SECONDS=5
ROUTEROS_DISCOVERY_INSECURE_TLS=false
```

`api` uses the RouterOS API service (normally TCP 8728). `api_ssl` uses API-SSL (normally TCP 8729) and is the production recommendation. TLS verifies certificates by default. `ROUTEROS_DISCOVERY_INSECURE_TLS=true` is accepted only in Laravel `local` or `testing` environments. Go independently rejects it unless `APP_ENV=local` and `GO_NETWORK_ENGINE_ALLOW_INSECURE_ROUTEROS_TLS=true`; both settings remain disabled by default. This is an explicit lab-only exception, never a production default.

Go has an explicit connect timeout and wraps each allowlisted read in an explicit read timeout. It normalizes connection failures without raw diagnostics or credentials: `ROUTER_UNREACHABLE`, `ROUTER_AUTH_FAILED`, `ROUTER_TIMEOUT`, `ROUTER_PROTOCOL_ERROR`, and `DISCOVERY_FAILED`.

Use a dedicated RouterOS discovery account with permissions limited to the required read paths; do not use a production full-admin credential. API/API-SSL must be enabled intentionally and reachable only through a controlled management network.

OLT, AP, CPE, SNMP, ICMP, REST, topology, local agents, automatic repair, and all RouterOS mutation remain out of scope.

## Discovery data and secrecy

Laravel stores a tenant-owned `network_discovery_snapshots` record as point-in-time evidence and upserts normalized `discovered_network_resources` records. Snapshot payloads, normalized resource data, client responses, fingerprints, audits, and logs recursively remove `password`, `pass`, `secret`, `token`, `authorization`, `credential`, and `credentials` fields.

Fingerprints are SHA-256 over recursively canonicalized stable normalized data. They do not include timestamps or secrets. The unique resource identity is tenant + router + resource type + fingerprint. Repeated identical discovery updates `last_seen_at` and the snapshot relationship while preserving `first_seen_at`; it cannot duplicate a resource.

## Management states

- `DISCOVERED`: found on the network; CosmicLink does not manage it.
- `ADOPTED`: explicitly mapped into CosmicLink while preserving existing network configuration.
- `MANAGED`: reserved for a future explicit management phase. Phase 6C never transitions to it and enables no mutation.

No automatic `DISCOVERED → ADOPTED` or `ADOPTED → MANAGED` transition exists.

## Reconciliation

Reconciliation is report-only. PPPoE resources are classified as:

- `NEW`: network resource has no CosmicLink mapping.
- `MATCHED`: adopted mapping and local network account agree with observed username/profile.
- `CONFLICT`: mapping/account identity is absent or mismatched.
- `CHANGED`: mapped local profile differs from the observed profile.
- `MISSING`: an adopted resource was absent from the latest successful snapshot.

No class automatically changes customers, package assignments, lifecycle state, or router configuration.

## Adoption and audit

An authenticated tenant operator may explicitly adopt only a `DISCOVERED` PPPoE account for a `CustomerConnection` owned by the same tenant and router. Adoption creates or links a local `NetworkAccount`, preserves the observed profile and enabled state, links the connection, changes the resource to `ADOPTED`, and writes `network_discovery_audits` evidence.

Discovery and adoption deliberately do **not** write `network_operation_logs`, because that log represents actual network-changing operations.

## Read-only proof

The fake Phase 6B execution provider tracks invocations of its mutation methods. The authenticated development-only endpoint `GET /v1/discovery/safety/mutation-count` returns the aggregate counter. The Phase 6C live integration test performs discovery, adoption, and repeated discovery, then requires the count to be `0`.

This endpoint is only a FakeProvider acceptance-test instrument; it does not expose device data or management operations and must not be considered a production observability interface.

## Hardware verification status — September 18, 2026

**PHASE 6D — FULLY VERIFIED for the documented read-only discovery scope.** An explicitly authorized MikroTik hEX running RouterOS **6.49.13 (long-term)** was reached over its LAN-local RouterOS API service using a dedicated `read,api` account with no `write` policy.

The verified path was Laravel → authenticated Go Network Engine → `RouterOSDiscoveryProvider` → RouterOS binary API. Real device metadata, PPPoE profiles/accounts, address pools, and simple queues were normalized and persisted. A second discovery created a new historical snapshot but no duplicate resources, preserved `first_seen_at`, advanced `last_seen_at`, and retained `DISCOVERED` state. Reconciliation remained stable.

A deterministic sanitized normalized-inventory fingerprint, excluding uptime and sensitive/volatile fields, matched before and after the hardware run. Checked Laravel/Go results, snapshots, normalized resources, audits, logs, and rendered UI contained neither the router login password nor PPPoE passwords. The real discovery engine's FakeProvider mutation counter remained `0`; no Phase 6B network-operation log was created; no `MANAGED` transition occurred.

The implementation records only the six allowlisted reads above. RouterOS mutation is **not implemented in Phase 6D**. Local adoption against the real inventory was not required for this acceptance run and remains a Laravel-only mapping operation when used.