# Phase 6K Step 4 Traffic Intelligence Dashboard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a responsive operator-facing Traffic Intelligence dashboard and compact Customer 360 traffic panel using only verified PostgreSQL analytics APIs.

**Architecture:** Add one authenticated Blade/Vue page with tenant-scoped filter bootstrap data and a compact Customer 360 Vue mount. A cancellable composable reads the existing Step 3 endpoints; dependency-free SVG components render bounded histories and hourly traffic without adding infrastructure or contacting RouterOS.

**Tech Stack:** Laravel, Blade, Vue 3, native Fetch/AbortController, SVG, existing CosmicLink CSS tokens, Node built-in test runner, PHPUnit.

**Spec:** `C:\xampp\htdocs\cosmiclink\.worktrees\phase-6k-read-only-traffic\docs\superpowers\specs\2026-09-22-phase6k-traffic-intelligence-dashboard-design.md`

## Global Constraints

- Base commit is `1d39d2982f99650a8717121c3a345b130a29d3bb`.
- Browser and page requests read PostgreSQL only through existing Step 3 APIs.
- Do not change RouterOS collection behavior, add polling, or add a chart dependency.
- Preserve the frozen CosmicLink design system and existing application layout.
- Keep subscriber and interface traffic visually and mathematically separate.
- Never render unsupported authoritative traffic as zero.
- Do not add billing, auto-isolation, Copilot, mutations, routing manipulation, alerts, or anomaly detection.

---

### Task 1: Pure dashboard formatting and state helpers

**Files:**
- Create: `resources/js/modules/traffic/trafficFormatters.js`
- Create: `resources/js/modules/traffic/trafficFormatters.test.js`
- Modify: `package.json`

**Interfaces:**
- Produce `formatBytes`, `formatThroughput`, `formatHour`, `chronological`, `rankingState`, and `dedupeRankingRows`.

- [ ] Write Node tests asserting KB/MB/GB/TB, Kbps/Mbps/Gbps, null handling, chronological ordering, empty/unavailable state classification, and one Hotspot username per router.
- [ ] Run `npm test`; expect failure because helpers/script do not exist.
- [ ] Implement pure helpers with no browser or Vue dependency and add `test:frontend`/`test` scripts using `node --test`.
- [ ] Run `npm test`; expect PASS.

### Task 2: Authenticated page, navigation, and filter bootstrap

**Files:**
- Create: `app/Http/Controllers/TrafficIntelligenceController.php`
- Create: `resources/views/traffic/index.blade.php`
- Modify: `routes/web.php`
- Modify: `resources/js/components/AppShell.vue`
- Create: `tests/Feature/Phase6KTrafficDashboardTest.php`

**Interfaces:**
- Produce authenticated route `traffic.index` at `/traffic`.
- Blade mount exposes JSON filter options for routers, customers, connections, and packages, all tenant-scoped.

- [ ] Write failing feature tests for guest redirect, authenticated page, navigation label, tenant-scoped bootstrap data, empty/unavailable state hooks, and zero collection/client invocation.
- [ ] Run `php artisan test tests/Feature/Phase6KTrafficDashboardTest.php --no-coverage`; expect route/page failures.
- [ ] Add the thin controller using four bounded tenant-scoped PostgreSQL queries, route, Blade mount, shell title, and Network navigation link.
- [ ] Run the focused feature test; expect PASS.

### Task 3: Cancellable analytics composable

**Files:**
- Create: `resources/js/modules/traffic/trafficQueries.js`
- Create: `resources/js/modules/traffic/trafficQueries.test.js`
- Create: `resources/js/composables/useTrafficAnalytics.js`

**Interfaces:**
- Produce deterministic query builders for existing overview, rankings, interface-history, peak-hours, and subscriber-history endpoints.
- Produce composable state `overview`, `rankings`, `interfaces`, `peakHours`, `loading`, `rankingLoading`, `error`, `refresh`, and `refreshRankings`.

- [ ] Write failing Node tests for period/filter/ranking query strings and stale-request cancellation using an injected request function.
- [ ] Run `npm test`; expect failure.
- [ ] Implement query builders that omit unsupported empty filters and encode values.
- [ ] Implement parallel refresh with `AbortController`, request generation checks, and ranking-only refresh.
- [ ] Run `npm test`; expect PASS.

### Task 4: Main dashboard and dependency-free SVG charts

**Files:**
- Create: `resources/js/modules/traffic/TrafficLineChart.vue`
- Create: `resources/js/modules/traffic/TrafficHourlyBars.vue`
- Create: `resources/js/modules/traffic/TrafficIntelligencePage.vue`
- Modify: `resources/js/app.js`
- Modify: `resources/css/app.css`
- Modify: `tests/Feature/Phase6KTrafficDashboardTest.php`

**Interfaces:**
- Consume the existing Step 3 API shapes without backend changes.
- Render period/filter controls, six KPIs, upload/download history, ranking controls/table, separate interfaces, peak hours, and explicit states.

- [ ] Extend the failing page test to assert mount configuration and static state/accessibility labels.
- [ ] Build six KPI cards with volume/rate-specific formatting and explicit unavailable behavior.
- [ ] Add chronological bounded SVG line and bar components with accessible summaries and no animation dependency.
- [ ] Add ranking mode/metric/top controls, mapped/unmapped rows, one Hotspot identity row, and explicit PPPoE unavailable state.
- [ ] Add interface selector/history panel labelled as network/interface traffic, never customer totals.
- [ ] Add loading, empty, API error, no-history, and authoritative-unavailable states.
- [ ] Add responsive traffic-specific CSS using existing tokens.
- [ ] Mount the page from `app.js` and run `npm test`, focused feature test, and `npm run build`; expect PASS.

### Task 5: Compact Customer 360 traffic panel

**Files:**
- Modify: `app/Http/Controllers/CustomerController.php`
- Modify: `resources/views/customers/show.blade.php`
- Create: `resources/js/modules/traffic/CustomerTrafficPanel.vue`
- Modify: `resources/js/app.js`
- Create: `tests/Feature/Phase6KCustomerTrafficPanelTest.php`

**Interfaces:**
- Blade exposes only mapped tenant-owned connection id, code, router id, and normalized network identity.
- Vue requests existing subscriber-history API separately for static queue and Hotspot modes.

- [ ] Write failing feature tests for authorization, compact mount data, customer isolation, no mapped identity state, and zero collection invocation.
- [ ] Run the focused test; expect failure because the mount is absent.
- [ ] Add a compact existing-panel section without changing Customer 360 layout structure.
- [ ] Implement period and connection controls plus combined visual history with modes labelled separately; never infer PPPoE or combine modes into an authoritative total.
- [ ] Mount from `app.js`, run Node tests, focused Customer 360 test, and build; expect PASS.

### Task 6: Verification and focused commit

**Files:**
- Review all Step 4 changes only.

- [ ] Run `npm test` and `npm run build`.
- [ ] Run all Phase 6K Laravel tests.
- [ ] Run the full Laravel suite where feasible.
- [ ] Run Pint on changed PHP files and rerun focused tests if formatting changes files.
- [ ] Run source searches proving UI dependencies contain no collection service, Go client, RouterOS, monitoring collect route, or write path.
- [ ] Run `git diff --check`, inspect complete diff, and verify no built assets or unrelated files are staged.
- [ ] Create exactly one commit: `Phase 6K add traffic intelligence dashboard`.
- [ ] Verify the local commit and clean working tree; do not push.