# CosmicLabs Design DNA Adoption

## Goal

Refine CosmicLink's existing Laravel/Vue operations interface so it visually belongs to the CosmicLabs product family while preserving the current information architecture, real operational data, routes, APIs, business rules, and theme persistence.

## Reference DNA

- Space Grotesk for page titles and display hierarchy.
- Plus Jakarta Sans for body copy, navigation, buttons, forms, and normal application content.
- JetBrains Mono for telemetry, timestamps, network identifiers, technical labels, and compact metadata.
- Deep navy/graphite dark surfaces with cobalt as the primary accent.
- Coordinated light mode using the same cobalt/neutral role system.
- Hairline borders, restrained shadows, and a radius hierarchy near 4px / 6px / 10px / 14px.
- Semantic green, amber, red, and blue statuses.
- Restrained 150–300ms interaction motion and reduced-motion support.

## Scope

Frontend visual refinement only. Modify existing shared CSS tokens/styles, the Vue app shell, the Vue monitoring surface, and the dashboard view where needed to express the visual hierarchy. Preserve all existing server-side data, form actions, routes, API contracts, outage lifecycle, simulation behavior, and tenant boundaries.

## Acceptance criteria

1. The shared UI uses the three approved font roles and no Instrument Serif application typography.
2. Dark mode presents a quiet graphite/deep-navy canvas with cobalt accents and no glow-heavy or glassmorphism-dominant panels.
3. Light mode remains functional and persists through the existing `cosmiclink-theme` local-storage key.
4. The shell retains the desktop sidebar, provides usable mobile navigation, and exposes one persistent global simulation indicator without duplicating it unnecessarily.
5. Dashboard metrics read as a compact operational telemetry strip; Network Fabric is retained but visually subordinate and simplified on narrow screens.
6. Monitoring rows prioritize state, latency/loss, observation time, and action using aligned readouts and mono telemetry.
7. Active outage incidents expose status, router, affected counts, age, lifecycle, and next action without coloring the entire surface red.
8. Shared tables, forms, buttons, focus states, empty states, and responsive layouts remain readable at 1440px, 1024px, 768px, and 390px without horizontal page overflow.
9. No backend or business-logic files are changed.

## Out of scope

Database schema, backend services, routes, billing/provisioning/outage rules, messaging behavior, tenant isolation, NetworkDriver, MonitoringDriver, real RouterOS/SNMP/ICMP integrations, Go implementation, dependency additions, and full CRUD-page redesigns.