# Safe ISP Migration — Phase 6C

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

## Current provider

`FakeDiscoveryProvider` is the only discovery implementation. It returns deterministic simulated data for `CORE-01`, PPPoE profiles `10M`, `20M`, `50M`, PPPoE accounts `existing-user-001` through `existing-user-003`, `pppoe-pool`, and a simulated relevant queue.

This is **not MikroTik discovery**. RouterOS/MikroTik, OLT, AP, CPE, SNMP, ICMP, real device connectivity, and all protocol libraries remain out of scope.

## Discovery data and secrecy

Laravel stores a tenant-owned `network_discovery_snapshots` record as point-in-time evidence and upserts normalized `discovered_network_resources` records. Snapshot payloads, normalized resource data, client responses, fingerprints, audits, and logs recursively remove `password`, `secret`, `token`, and `credentials` fields.

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