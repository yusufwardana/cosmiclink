# Phase 6K Step 4 Traffic Intelligence Dashboard Design

## Purpose

Add an authenticated operator dashboard over the verified Phase 6K Step 3
PostgreSQL analytics APIs. The browser never initiates collection and never
contacts the Go network engine or RouterOS.

## Page architecture

`GET /traffic` renders the existing application layout and one Vue mount. The
Blade controller supplies tenant-scoped router, customer, connection, and
package filter options from PostgreSQL. Vue requests only the existing
`/api/v1/traffic/*` read endpoints.

The page uses the existing CosmicLink panel, stat, table, badge, typography,
and responsive layout vocabulary. New CSS is limited to traffic-specific
controls, SVG charts, ranking rows, and responsive behavior.

## Request coordination

One composable owns the active period and supported filters. A refresh issues
overview, ranking, interface-history, and peak-hours reads in parallel. An
`AbortController` cancels the previous refresh and a monotonically increasing
request id prevents stale responses from replacing newer state.

Ranking mode, metric, and top count refresh only rankings. Selecting PPPoE
renders the API's `AUTHORITATIVE_TRAFFIC_UNAVAILABLE` state; it never displays
zero as authoritative traffic.

## Visualizations

No chart dependency is added. Small Vue SVG components render bounded series:

- chronological hourly upload/download lines from `peak-hours`;
- hourly total bars for peak-hour inspection;
- chronological per-interface upload/download lines from `interface-history`.

The interface panel is visually and textually separate from subscriber KPIs
and rankings. No interface volume is combined with customer totals.

## Formatting

Pure helpers format bytes and bits per second using binary volume units and
decimal network-rate units respectively. Missing values render an em dash.
Zero renders as zero only for a supported dataset; unsupported authoritative
data renders the explicit unavailable state.

## Customer 360

Customer 360 receives a compact Vue mount with tenant-owned connection
identities already loaded by the existing controller. It presents Today, 7
Days, and 30 Days controls, lets the operator select a mapped connection, and
requests subscriber history for static queue and Hotspot modes separately.
It does not guess a mode and does not redesign Customer 360.

## Testing

Laravel feature tests cover page authorization, navigation/mount/filter data,
Customer 360 integration, unavailable and empty-state markup, and strict proof
that page requests never invoke collection. Node's built-in test runner covers
formatters, chronological chart normalization, ranking query generation,
period/top/mode changes, stale-request cancellation, Hotspot de-duplication,
and state classification. Existing Phase 6K API tests continue to prove
tenant scoping and PostgreSQL-only behavior.

## Exclusions

No collection changes, polling, RouterOS calls, mutations, billing,
auto-isolation, Copilot, routing manipulation, alerts, anomaly detection, or
large frontend dependency.