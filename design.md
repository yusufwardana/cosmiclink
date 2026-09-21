# Design — CosmicLink

A locked design system for this app. Every page redesign reads this file before
emitting code. Do not regenerate per page — extend or amend this file when the
system needs to grow.

Locked by Hallmark `redesign`, multi-page flow, `scope: app` (Phase 6E). The
system is a futuristic diagnostic console: dark/light navy surfaces, cyan/blue
telemetry accents, terminal-style panel headers, compact metric readouts, and
hairline separation. It extends Phase 6D without changing behavior or route
ownership.

## Genre
editorial

An operational instrument, not editorial prose. Sans-first hierarchy for page
titles, metrics, panels and body copy; restrained mono labels for technical
metadata; hairline rules carry structure; tabular numerals serve readouts.
Prose stays sentence case — operators read error strings.

## Macrostructure family
- **App console pages** — `05 · Workbench`. Instrument panels, framed readouts,
  no marketing copy. Knobs: panel count, apparatus figure, split vs. halves.
  `dashboard`, `monitoring/*`.
- **App list pages** — `13 · Index-First`. The page IS the list. Knobs: density
  (comfortable / dense), grouping (flat / by parent record), stat band above
  (yes / no), and `11 · Catalogue` when the records are inventory rather than a
  work queue (`packages/index`).
- **App record pages** — `F3 · Tabular spec sheet`. Key/value rows, hairline
  rule between rows, tabular numerics, footnote in the margin. Knobs: stat band,
  related-record panel, `.feed` list of recent activity. `customers/show` is the
  one `15 · Split Studio` diptych (record on the left, activity rail on the
  right).
- **App form pages** — `F3 · Tabular spec sheet with inputs as values`. The
  create/edit page mirrors the record page it produces: same key column, same
  order, same footnote-in-margin register. A form that does not look like the
  record it writes is the bug this fixes.
- **Guest page** — `auth/login` keeps the blueprint-grid surface from 6A.2.

## Theme — Futuristic Diagnostic Console, dark + light
`resources/css/tokens.css` is the source of truth and the `--cl-*` names are
canonical — no parallel set. Role → token:

- paper     `--cl-canvas`     oklch(10% 0.018 205)
- paper-2   `--cl-canvas-2`   oklch(13% 0.022 205)
- surface   `--cl-surface-1`  oklch(14% 0.024 211) · `--cl-surface-2` oklch(18% 0.028 211)
- ink       `--cl-ink`        oklch(96% 0.012 190)
- ink-2     `--cl-ink-2`      oklch(84% 0.018 195)
- muted     `--cl-muted`      oklch(68% 0.025 200)
- faint     `--cl-faint`      oklch(51% 0.026 205)
- rule      `--cl-hairline`   oklch(29% 0.035 211) · `--cl-hairline-2` oklch(43% 0.045 211)
- accent    `--cl-accent`     oklch(76% 0.12 178)  teal signal and rules
- accent-2  `--cl-accent-2`   oklch(72% 0.13 285)  restrained violet focus
- brand     `--cl-cyan`       oklch(82% 0.14 190)  network identity
- focus     `--cl-focus`      oklch(82% 0.16 190)
- states    ok oklch(81% 0.125 158) · brass oklch(83% 0.135 74) · danger oklch(72% 0.155 25)

Accent budget: cyan/blue ≤ 5 % of a viewport, with restrained radial ambient
layers only. No mesh gradient, animation, glow, or floating blob. State colour appears only inside `.ui-status-badge` and
`.stat-card--warning` / `--danger`.

`data-theme="dark"` is the default authenticated mode. `data-theme="light"`
uses cool white/pale-cyan surfaces with the same semantic roles. The shell
toggle persists the preference in `localStorage` and applies the attribute before
the app mounts to avoid a theme flash. Glass is reserved for functional overlays
and controls (`.glass-surface`, theme toggle); tables and data panels remain
opaque for readability. The grid is reserved for `.apparatus` visualization
surfaces, never the document canvas.

## Typography
- Display: system sans (`ui-sans-serif`, system-ui, Segoe UI), semibold. The
  dashboard is an operations console, so H1s and metric values are not serif.
- Body: the same system sans stack. It is available on the platform and does
  not pretend to load a missing `Instrument Sans` asset.
- Optional asset: `'Instrument Serif'` remains self-hosted for future brand or
  guest-only use, but is not part of the authenticated dashboard hierarchy.
- Mono: `--cl-font-label` (ui-monospace stack) for every label, code,
  reference, timestamp and numeric readout.
- Tracking: display −0.025em · `--cl-tracking-label` 0.08em ·
  `--cl-tracking-micro` 0.14em.
- Type scale anchor: `--cl-text-display` = clamp(28px, 2vw + 1rem, 36px).
- UPPERCASE is for labels only. Every sentence a human reads is sentence case.

## Spacing
4-point named scale (`--cl-space-2xs` … `--cl-space-3xl`, `--cl-section-gap`)
from `tokens.css`. Pages use named tokens, never raw values. Section rhythm is
`--cl-section-gap`; ledger rows carry their own padding. List pages run tighter
than console pages — density is the list page's one loud decision.

## Motion
- Easings: `--cl-ease-out` cubic-bezier(0.16, 1, 0.3, 1) · `--cl-ease-soft`
  cubic-bezier(0.22, 0.61, 0.36, 1). No `ease`, no bounce, no overshoot.
- Reveal pattern: **none.** App pages carry no scroll reveals and no entrance
  animation. Hover / active / focus transitions only, ≤ `--cl-dur-short`.
- Reduced-motion fallback: the global kill-switch in `app.css` collapses every
  duration to 0.01 ms. New pages inherit it and must not opt out.
- Interactive cards may shift by at most 2px and use only border, surface and
  tight shadow changes; no floating decorative elements or scroll reveals.
- Diagnostic console pages may use compact terminal headers (`label // ordinal`),
  segmented readouts and apparatus grids, but never fabricate status or code
  content: all values remain bound to existing records.
- Dashboard console rhythm is compact by default: the telemetry strip and
  primary diagnostic row use `--cl-space-lg` or less; the apparatus connectors
  are short; secondary actions remain compact instead of filling the panel.
- The fixed topbar owns an opaque safe area (`--cl-topbar-safe-h`) so scrolling
  records never bleed underneath the diagnostic header. Scroll anchors use the
  same offset; the progress line remains the highest shell layer.
- Motion is dependency-free and operational: real metric values may count up
  once with `requestAnimationFrame`; the shell may show a 2px scroll progress
  line on long pages. GSAP, Framer Motion, parallax and cursor effects are out
  of scope. Reduced-motion users receive final values and no smooth scrolling.

## Microinteractions stance
- Silent success. No toasts. `.alert` carries errors and validation only.
- Hover delay 0, focus delay 0. Focus rings appear instantly — never
  transitioned in.
- No confirmation dialog for a reversible row action.
- Async monitoring errors use an assertive live region; icon-only controls carry
  an accessible label or title.
- Tables keep the hover row tint. No row lift, no shadow, no stripe.

## CTA voice
- Primary: cyan fill, pill radius, `button[type=submit]`. One per page.
- Secondary: outline pill, transparent — `.button--quiet`.
- Tertiary: mono UPPERCASE micro link — `.button--sm` or `.section-heading > a`.
- Row-level actions: links or `.button--sm` inside a `.cell-actions` cluster,
  right-aligned. Never a filled button per row.
- The single primary action belongs in `.page-header__aside` **or** the form's
  action row — never both on one page.

## Per-page allowances
- App pages MUST NOT use enrichment. No illustrative SVG, no CSS art, no
  decorative badge. Function carries the page. The one figure allowed is the
  6A.2 apparatus on the dashboard.
- No new fonts, no new colours, no new radii, no new shadows. Amend this file
  before adding any of them.

## What pages MUST share
- The wordmark (`.brand__mark`) and the shell (`.sidebar`, `.topbar`, `AppShell.vue`).
- The accent colour and its placement (≤ 5 % of a viewport).
- Sans display + body stack and mono technical metadata; the label/prose case split.
- CTA voice: compact rounded controls, hairline border, mono tertiary actions.
- Page-header rhythm: `.eyebrow` (`__ord` + `__sep` + label) → `h1` →
  `.page-header__meta`, with actions in `.page-header__aside`.
- Every record state through `.ui-status-badge`. Raw `strtoupper($status)`
  prints are the bug being fixed.
- Panel anatomy: `.panel` + `.panel__head` (`.panel__kicker`, `.panel__title`,
  `.panel__meta`) + `.panel__body` (`--flush` when the body is a table).
- The spec-sheet key column width (`--cl-key-col`) across record and form pages.
- Empty states through `.empty-state` — a quiet sentence, never a dashed box.
- Tables as the ledger: the global `table` / `th` / `td` rules already are the
  readout. New pages name cells (`.cell-key`, `.cell-sub`, `.cell-actions`,
  `.is-numeric`) and add no new table shell.

## What pages MAY differ on
- Macrostructure within the page-type family — Index-First vs. Catalogue for
  two different lists; Split Studio for Customer 360 only.
- Density knob on list pages, and which columns survive ≤ 900px
  (`.cell-optional` is dropped; key + state always survive).
- Whether a record page carries a stat band, a related-record panel, or a
  `.feed` activity list — this differs legitimately per record type.
- Panel count and `.ops-grid` choice (`--split` / `--halves` / `--thirds`).

## Page inventory
17 pages in 3 batches. The ordinal in `.eyebrow__ord` encodes page identity and
is fixed here so the same record always carries the same number in the eyebrow,
on both the record page and its form.

| Ord | Page | Type | Macrostructure |
| --- | --- | --- | --- |
| 00 | `dashboard` | console | Workbench (05) — 6A.2, unchanged |
| 01 | `customers/index` | list | Index-First (13) |
| 02 | `customers/show`, `customers/form` | record · form | Split Studio (15) · F3 form |
| 03 | `packages/index` | list | Catalogue (11) |
| 04 | `packages/show`, `packages/form` | record · form | F3 spec sheet · F3 form |
| 05 | `connections/form` | form | F3 form |
| 06 | `billing/invoices/index` | list | Index-First (13) |
| 07 | `billing/invoices/show` | record | F3 spec sheet |
| 08 | `billing/payments/index` | list | Index-First (13) |
| 09 | `billing/payment-requests/show` | record | F3 spec sheet |
| 10 | `routers/index` | list | Index-First (13) + stat band |
| 11 | `routers/show`, `routers/form` | record · form | F3 spec sheet · F3 form |
| 12 | `network/accounts` | list | Index-First (13), dense |
| 13 | `network/logs` | list | Index-First (13), dense |
| 14 | `messages/index` | list | Index-First (13) |

Console pages already built: `monitoring/index` (Vue), `monitoring/history`,
`monitoring/incident`. This pass only consistency-checks them.

## Untouched by this system
Routes, controllers, models, policies, migrations, API resources and tests are
out of scope — Hallmark is the visual layer. `resources/views/welcome.blade.php`
is dead stock with zero references; it is **not** deleted, and it is not part of
the system.

## Exports
Drop-in formats for re-using this system elsewhere. Only the first is wired in
this project.

### tokens.css
`resources/css/tokens.css` — canonical. `--cl-*`, oklch only, plus the legacy
aliases kept for pre-6A.1 markup. `--cl-key-col` is the spec-sheet key column.

### Tailwind v4 `@theme`
Not wired here: designed pages use semantic classes on the native token layer,
and `app.css` keeps `@import 'tailwindcss'` as its first line untouched. Ask
for `extend design.md with Tailwind exports` if a future page needs utilities.


