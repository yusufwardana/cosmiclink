# CosmicLabs Design DNA Adoption Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Adapt CosmicLink's existing operations UI to the CosmicLabs cobalt/graphite design language without changing backend behavior or operational contracts.

**Architecture:** Keep the current Laravel-rendered pages, Vue app shell, and Vue monitoring module. Centralize the visual system in the existing `--cl-*` token layer and shared `app.css`, then make only presentation-level template refinements in the shell, dashboard, and monitoring view. No new UI framework, API, route, state, or dependency is introduced.

**Tech Stack:** Laravel 12, Blade, Vue 3, Vite, Tailwind CSS 4 import layer, vanilla CSS custom properties, PHPUnit, Pint, Playwright/browser tooling.

**Spec:** `C:\xampp\htdocs\cosmiclink\docs\superpowers\specs\2026-09-17-cosmiclabs-design-dna-adoption.md`

## Global Constraints

- Frontend visual refinement only; do not change database schema, backend/business logic, routes, APIs, billing rules, provisioning rules, outage correlation, payment behavior, messaging behavior, tenant isolation, NetworkDriver, MonitoringDriver, or Go implementation.
- Use CosmicLabs Project as the primary visual reference: Space Grotesk, Plus Jakarta Sans, JetBrains Mono, cobalt/graphite surfaces, hairline rules, restrained radii and motion.
- Preserve current Laravel/Vue architecture, information architecture, real operational data, theme persistence, and simulation controls.
- Do not add dependencies or copy React components from the reference repository.
- Verify 1440px, 1024px, 768px, and 390px layouts with no horizontal page overflow.

---

### Task 1: Replace the legacy visual token direction with CosmicLabs roles

**Files:**
- Modify: `C:\xampp\htdocs\cosmiclink\resources\css\tokens.css`
- Test: `C:\xampp\htdocs\cosmiclink\resources\css\tokens.css` via build and browser computed-style inspection

**Interfaces:**
- Produces canonical `--cl-*` font, canvas, surface, border, accent, semantic-status, radius, motion, and shell tokens consumed by `app.css` and existing markup.

- [ ] **Step 1: Remove the Instrument Serif declaration and legacy Aurora/Cinder naming/comments** while retaining compatibility aliases used by existing markup.
- [ ] **Step 2: Add the approved font stacks** with Space Grotesk as `--cl-font-display`, Plus Jakarta Sans as `--cl-font-body`, and JetBrains Mono as `--cl-font-label`; use remote Google font loading only if already acceptable to the existing app, otherwise provide robust system fallbacks without adding a package.
- [ ] **Step 3: Retune dark tokens** toward deep navy/graphite canvas and cobalt accent; reduce cyan/teal/violet decorative roles and keep green/amber/red semantic roles.
- [ ] **Step 4: Retune light tokens** as a coordinated cool-neutral/cobalt system, including hairlines, focus, surfaces, and readable contrast.
- [ ] **Step 5: Set the reference radius hierarchy** close to 4px, 6px, 10px, and 14px, reduce shadow/elevation roles, and remove unused glow/glass/grid emphasis from the token source.
- [ ] **Step 6: Preserve legacy aliases and shell geometry** so existing Blade/Vue pages continue rendering without markup-wide rewrites.
- [ ] **Step 7: Run `npm run build`** and inspect the diff for accidental non-CSS changes.

### Task 2: Align global application surfaces, controls, tables, and responsive rules

**Files:**
- Modify: `C:\xampp\htdocs\cosmiclink\resources\css\app.css`
- Test: `C:\xampp\htdocs\cosmiclink\resources\css\app.css` via build and responsive browser inspection

**Interfaces:**
- Consumes the tokens from Task 1.
- Produces shared styles for Blade pages, AppShell, MonitoringPage, tables, forms, buttons, status badges, panels, and responsive layouts.

- [ ] **Step 1: Make the page canvas neutral graphite/navy** by removing decorative radial blooms from ordinary pages and keeping visual emphasis in accents and semantic states.
- [ ] **Step 2: Apply typography discipline** so headings use display face, body/UI uses Jakarta Sans, and technical labels/values use mono without making prose uppercase.
- [ ] **Step 3: Refine shell styles** for a quieter sidebar, compact utility rail, restrained separators, active navigation, tenant/operator context, theme toggle, and focus states.
- [ ] **Step 4: Refine panels and metric bands** into neutral surfaces with hairline rules, compact spacing, and cobalt only for active/interactive roles.
- [ ] **Step 5: Refine tables, forms, buttons, alerts, empty states, and status badges** with semantic hierarchy and dangerous-action differentiation.
- [ ] **Step 6: Make responsive rules explicit for 1024px, 768px, and 390px**, including compact two-column telemetry where appropriate, mobile drawer usability, clipped page overflow, and simplified topology/readout treatment.
- [ ] **Step 7: Preserve and strengthen reduced-motion behavior** for transitions and existing counter/status animation.
- [ ] **Step 8: Run `npm run build` and `git diff --check`.**

### Task 3: Refine the shell's visual copy and simulation indicator placement

**Files:**
- Modify: `C:\xampp\htdocs\cosmiclink\resources\js\components\AppShell.vue`
- Test: browser navigation and theme persistence on authenticated shell pages

**Interfaces:**
- Consumes existing props and route data: `baseUrl`, `csrfToken`, `currentRoute`, `tenant`, `operator`, and `simulation`.
- Produces the same links, forms, theme key, and mobile navigation behavior with presentation-only wording/placement changes.

- [ ] **Step 1: Keep the existing navigation groups and route targets unchanged.**
- [ ] **Step 2: Keep one persistent simulation indicator in the shell context** and remove duplicate topbar/sidebar presentation only where it is redundant; retain page-local simulation context where an action requires it.
- [ ] **Step 3: Refine brand/tenant/operator markup only if needed for the shared CSS hierarchy**, without changing data sources or logout behavior.
- [ ] **Step 4: Verify keyboard focus, mobile open/close, active-link state, and dark/light toggle manually in the browser.**

### Task 4: Rebalance dashboard hierarchy and compact Network Fabric

**Files:**
- Modify: `C:\xampp\htdocs\cosmiclink\resources\views\dashboard.blade.php`
- Test: dashboard rendered page with nominal and populated operational data

**Interfaces:**
- Consumes existing Blade variables and route/form actions exactly as currently defined.
- Produces the existing dashboard sections with revised presentation hierarchy only.

- [ ] **Step 1: Change dashboard heading copy to the approved application framing** while preserving the existing page meaning and tenant context.
- [ ] **Step 2: Keep the four real metrics but present them as a compact operational strip** with Customers, Online/active connections, Billing attention, and Active incidents labels aligned to the available variables.
- [ ] **Step 3: Reduce Network Fabric dominance** by preserving Internet/Core → Router → PPPoE → subscribers relationships while removing unnecessary visual weight and empty apparatus space.
- [ ] **Step 4: Keep active incidents neutral but information-dense**, exposing router, lifecycle, affected connections, detection time, and the existing action/detail links.
- [ ] **Step 5: Preserve billing controls and real values, but ensure normal invoice generation and enforcement actions have distinct semantic classes.**
- [ ] **Step 6: Preserve recent payment and operation-log data while allowing shared ledger/readout styles to carry the hierarchy.**
- [ ] **Step 7: Render the page at all required widths and verify no horizontal overflow.**

### Task 5: Refine Monitoring rows and outage intelligence presentation

**Files:**
- Modify: `C:\xampp\htdocs\cosmiclink\resources\js\modules\monitoring\MonitoringPage.vue`
- Test: monitoring browser flow including simulation controls and incident lifecycle UI

**Interfaces:**
- Consumes the existing `useMonitoring()` payload and post paths.
- Produces the same monitoring check, router/connection simulation, acknowledge, incident detail, and lifecycle actions.

- [ ] **Step 1: Preserve all computed data, API paths, and lifecycle logic.**
- [ ] **Step 2: Structure router rows around ROUTER, STATE, LATENCY, LOSS, LAST OBSERVED, and ACTION columns.**
- [ ] **Step 3: Structure connection rows around CUSTOMER/CONNECTION, STATE, ROUTER, and LAST OBSERVED, with technical values in mono.**
- [ ] **Step 4: Make outage incident facts immediately scannable without changing the incident payload or coloring the whole incident surface red.**
- [ ] **Step 5: Keep lifecycle and communication/detail links visible; use primary/danger styling only where the existing action semantics require it.**
- [ ] **Step 6: Verify nominal empty state, degraded/offline state, mobile readability, and simulation actions in the browser.**

### Task 6: Verify all required checks and inspect the final diff

**Files:**
- Inspect: all files modified in Tasks 1–5
- Test: repository build, tests, lint, browser pages, and diff hygiene

- [ ] **Step 1: Run `npm run build`.** Expected: Vite production build succeeds.
- [ ] **Step 2: Run `vendor/bin/phpunit`.** Expected: full PHPUnit suite passes without backend changes.
- [ ] **Step 3: Run `vendor/bin/pint --test`.** Expected: no PHP formatting violations.
- [ ] **Step 4: Run `git diff --check`.** Expected: no whitespace errors.
- [ ] **Step 5: Start/use the existing browser environment and inspect Login, Dashboard, Monitoring, outage incident state, Customers, Customer 360, Billing, Routers, and Network logs at 1440px, 1024px, 768px, and 390px.**
- [ ] **Step 6: Verify theme persistence, shell behavior, no horizontal overflow, focus/hover states, empty/populated tables, and zero unexpected console/API errors.**
- [ ] **Step 7: Re-read all edited files and compare the result against the CosmicLabs reference; report exact pass/fail results and remaining frontend issues.**