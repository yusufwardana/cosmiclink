# Phase 6K Step 2 — Read-Only Traffic Collection Design

## Status

Approved in chat on September 22, 2026. This design extends the existing
MikroTik → Go Network Engine → Laravel Core → PostgreSQL monitoring path. It
does not create a parallel monitoring stack.

## Goal

Collect useful RouterOS traffic counters safely and persist restart-safe
historical usage in PostgreSQL. The collector must support router interfaces,
simple queues, active hotspot sessions, hotspot username aggregation, router
health context, and optional DHCP/ARP enrichment without performing RouterOS
writes or per-customer polling.

## Non-goals

- Billing from traffic usage.
- Traffic shaping, queue creation, session disconnects, or any RouterOS write.
- Browser-triggered traffic polling.
- Per-customer RouterOS API calls.
- Combining interface, queue, and hotspot counters into one universal total.
- Redis as historical storage.
- Replacing the existing health observation and outage-correlation flow.

## Architecture

Laravel remains the cadence and persistence owner. Go remains the network-local
read and normalization owner.

```text
Laravel scheduler
  → TrafficCollectionService
  → GoNetworkMonitoringClient
  → POST /api/v1/monitoring/traffic/collect
  → Go monitoring.Provider traffic collection
  → one RouterOS connection and sequential batch reads
  → normalized traffic snapshot
  → PostgreSQL collection, sample, delta, and bucket rows
```

The existing `POST /api/v1/monitoring/collect` health route remains compatible.
Traffic collection uses a separate scheduled-only route because adding all
traffic datasets to ordinary health checks would make manual/browser health
checks expensive and would repeat traffic reads during per-connection health
observation.

No web controller or browser API route exposes a direct traffic-poll action.

## RouterOS safety boundary

### Required batch reads

1. `/system/resource/print`
2. `/interface/print`
3. `/queue/simple/print`
4. `/ip/hotspot/active/print`

### Optional slow enrichment reads

5. `/ip/dhcp-server/lease/print`
6. `/ip/arp/print`

DHCP and ARP are requested only when their slower enrichment checkpoint is due.
The initial default is 300 seconds.

The transport interface remains `Connect`, `Read`, and `Close`. The provider
must reject commands outside this exact allowlist before dispatch. It must not
accept arbitrary RouterOS paths or command arguments. One collection opens one
connection and performs each requested dataset read once, sequentially.

## Normalized snapshot contract

The traffic response contains:

- `collected_at` in UTC;
- normalized router resource health;
- collection evidence listing completed datasets and whether enrichment ran;
- interface samples;
- simple queue samples;
- hotspot active-session samples;
- optional DHCP lease and ARP enrichment rows.

Only whitelisted, non-secret fields are returned. Passwords, secrets, tokens,
authorization values, and raw RouterOS errors are excluded.

### Interface samples

- Stable source identity: RouterOS `.id`.
- Display metadata: `name`, `type`, `running`, `disabled`.
- Absolute counters: `rx-byte`, `tx-byte`.
- Stored normalized direction:
  - `download_bytes = rx-byte`
  - `upload_bytes = tx-byte`

Rows without a non-empty `.id` are ignored because they cannot participate in
restart-safe deltas.

### Simple queue samples

- Stable source identity: RouterOS `.id`.
- Identity/enrichment metadata: `name`, `target`, `disabled`, `dynamic`.
- Absolute counters: RouterOS `bytes`, parsed as upload/download pair.
- Runtime evidence: RouterOS `rate`, parsed as upload/download pair.
- Configured limits: `max-limit`, parsed as upload/download pair.
- Stored normalized direction follows RouterOS simple-queue pair order:
  - first value is upload;
  - second value is download.

Malformed counter pairs do not become zero. The row is retained as metadata but
its counters are null and cannot produce deltas.

### Hotspot active-session samples

- Session identity uses RouterOS `.id` plus normalized username, address, and
  MAC address. The `.id` is required; the additional fields prevent an
  accidentally reused dynamic ID from aliasing a different session.
- Identity metadata: `user`, `address`, `mac-address`, `server`, `login-by`, and
  `uptime`.
- Absolute counters:
  - `download_bytes = bytes-in`
  - `upload_bytes = bytes-out`

Hotspot active-session counters are the sole authoritative source for hotspot
user traffic.

### DHCP and ARP enrichment

DHCP lease and ARP rows may enrich an IP or MAC with hostname, lease identity,
interface, and status metadata. They are not traffic sources and never
contribute bytes or deltas.

## PostgreSQL schema

### `traffic_collections`

One row per accepted tenant/router collection:

- `id`
- `tenant_id`
- `router_id`
- `collected_at`
- `provider`
- `datasets` JSON
- `enrichment_collected` boolean
- `metadata` JSON, limited to bounded collection evidence
- timestamps

Uniqueness: `(tenant_id, router_id, collected_at)`. A duplicate collection is
idempotently ignored.

### `traffic_samples`

One row per normalized source in a collection:

- `id`
- `traffic_collection_id`
- `tenant_id`
- `router_id`
- `source_type`: `interface`, `simple_queue`, or `hotspot_session`
- `source_key`
- `subject_key` nullable; hotspot username or queue target/name projection
- `upload_bytes` nullable absolute counter
- `download_bytes` nullable absolute counter
- `upload_delta_bytes` nullable
- `download_delta_bytes` nullable
- `delta_status`
- `observed_at`
- `metadata` JSON
- timestamps

Uniqueness: `(traffic_collection_id, source_type, source_key)`.

`delta_status` is one of:

- `valid`
- `first_observation`
- `counter_reset`
- `source_reappeared`
- `duplicate`
- `delayed_observation`
- `counter_unavailable`

### `traffic_buckets`

Idempotent five-minute aggregates of valid sample deltas:

- `id`
- `tenant_id`
- `router_id`
- `source_type`
- `subject_key`
- `bucket_started_at`
- `upload_bytes`
- `download_bytes`
- `sample_count`
- timestamps

Uniqueness:
`(tenant_id, router_id, source_type, subject_key, bucket_started_at)`.

Buckets preserve separate telemetry domains. Interface, simple-queue, hotspot
session, and hotspot username values are never added to each other.

## Persistence and serialization

Laravel accepts a traffic snapshot inside one database transaction and acquires
`Router::lockForUpdate()` for the exact tenant/router. This serializes concurrent
scheduler/manual process overlap without a broad tenant lock.

The service verifies that:

- the router still belongs to the supplied tenant;
- the response is structurally valid and marked reachable;
- `collected_at` is parseable and not unreasonably in the future;
- no existing collection has the same tenant/router/time identity.

PostgreSQL is the authoritative restart-safe state. Redis is not required. If
Redis is introduced later, it may coordinate scheduling only and may not replace
the persisted absolute counters or deltas.

## Counter delta rules

For each `(tenant_id, router_id, source_type, source_key)`, the service finds the
latest earlier sample.

A delta is `valid` only when all conditions hold:

1. the current observation is newer than the prior sample;
2. the source appeared in the immediately preceding accepted successful
   collection for that router;
3. both prior and current absolute counters are available;
4. both current counters are greater than or equal to their prior values.

Otherwise:

- no prior sample → `first_observation`, null deltas;
- same observation time or duplicate collection → `duplicate`, no bucket write;
- current time older than latest accepted collection → `delayed_observation`,
  null deltas and no bucket write;
- prior source exists historically but was absent from the immediately preceding
  collection → `source_reappeared`, null deltas;
- either counter decreased → `counter_reset`, null deltas;
- a counter is missing or malformed → `counter_unavailable`, null deltas.

This deliberately sacrifices one interval after disappearance, reset, or
collector ambiguity rather than fabricating usage. Collector process restarts do
not alter the rule because prior state comes from PostgreSQL.

## Hotspot aggregation and anti-double-counting

Each valid hotspot-session delta is written to its session bucket. The same
delta is also included once in a derived `hotspot_username` bucket keyed by the
normalized username.

Multiple simultaneous sessions for one username are summed. A session row can
contribute at most once because traffic samples are unique within a collection
and collection persistence is transactional/idempotent.

Simple-queue counters are never added to hotspot-session or hotspot-username
counters. Queues provide independent runtime/limit evidence and authoritative
traffic only for consumers explicitly reading the `simple_queue` domain, such
as fixed/IP customers without hotspot sessions.

Interface counters remain link/device telemetry and are not customer usage.

## Scheduling and retention

The existing Laravel scheduler remains the only cadence owner.

- Traffic collection default: every minute, aligned with the existing
  `monitoring:run` schedule but executed once per router rather than once per
  observed customer connection.
- DHCP/ARP enrichment default checkpoint: 300 seconds.
- Raw collection/sample retention default: 14 days.
- Five-minute bucket retention default: 90 days.
- Pruning runs daily and is tenant-neutral because retention policy is global;
  all queries still delete by time only from already tenant-scoped rows.

Configuration keys live under `config/monitoring.php` and are environment
overridable. The traffic collector is enabled only when the monitoring driver is
`engine` and traffic collection is explicitly enabled. Fake/default monitoring
behavior remains unchanged.

## Failure behavior

Router/engine failures produce no traffic collection or synthetic zero samples.
The existing router health flow remains responsible for health observations and
outage semantics.

A partial RouterOS traffic cycle is rejected rather than combining datasets
from different times. Optional DHCP/ARP failure may omit enrichment while the
four required traffic datasets remain successful.

All external failures use bounded safe codes. Raw RouterOS errors and
credentials are never stored or returned to Laravel.

## Tests

### Go

- Exact required and optional command allowlists.
- One connection and one read per requested dataset.
- Interface, queue, and hotspot field normalization.
- Malformed/missing counters remain unavailable rather than becoming zero.
- Stable hotspot session identity.
- Secret exclusion and zero mutation evidence.
- API authentication, strict decoding, and normalized traffic response.

### Laravel

- First observation creates null deltas.
- Increasing counters create exact deltas and bucket totals.
- Decreasing counters create `counter_reset` with no bucket increment.
- Missing then reappearing dynamic source creates `source_reappeared`.
- Duplicate and delayed collections do not modify buckets.
- Persisted state survives a new service instance/collector restart.
- Tenant and router histories never cross-contaminate.
- Two hotspot sessions for one username aggregate exactly once each.
- Simple queue and hotspot totals remain separate.
- DHCP/ARP enrichment runs only when due and never contributes bytes.
- Scheduler performs one traffic collection per eligible router.
- Secret-bearing response keys are removed before persistence.

## Verification

- `go test ./...`
- `go build ./...`
- `go vet ./...`
- focused Laravel Phase 6K tests
- full feasible `php artisan test`
- migration rollback/re-run in the test database
- source scan confirming the traffic provider contains only allowlisted print
  commands and no write-capable transport
- short real-router smoke run only when local observer credentials and a safe
  target are available; otherwise report it as not run

## Rollback

Disable traffic collection through configuration. Existing health monitoring
continues unchanged. The new tables can be rolled back without modifying
`health_observations`, outage incidents, network accounts, or RouterOS state.

## Expected implementation surface

Go:

- extend `network-engine/internal/monitoring` with traffic contract,
  normalization, and provider collection;
- add a scheduled-only traffic API handler in
  `network-engine/internal/api/server.go`;
- add focused monitoring/API tests.

Laravel:

- extend `GoNetworkMonitoringClient` with traffic collection;
- add traffic migrations/models/service;
- extend `config/monitoring.php` and `routes/console.php`;
- add focused Phase 6K tests.

No frontend changes, mutation-provider changes, billing changes, or new runtime
dependencies are included.