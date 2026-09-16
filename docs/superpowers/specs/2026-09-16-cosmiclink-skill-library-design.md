# CosmicLink Skill Library Design

## Goal

Create a project-local, domain-specific skill library under `.agents/skills`
that guides future CosmicLink development without adding runtime dependencies or
pretending that the Laravel application already exists.

## Decisions

- Canonical discovery root: `C:\xampp\htdocs\cosmiclink\.agents\skills`.
- Every skill is a directory containing a `SKILL.md` with YAML frontmatter
  fields `name` and `description`.
- Phase 0 is the only active implementation scope.
- The current hardware target is MikroTik RouterOS v6.49.13.
- Real RouterOS communication is out of scope; use `FakeNetworkDriver` for Phase
  0 and reserve `RouterOsV6NetworkDriver` for a later phase.
- External skills remain separate from CosmicLink skills. The installed RPA
  skills are not copied into the domain library.

## Skill boundaries

The library contains architecture, Laravel, database, tenancy, network-driver,
RouterOS, security, testing, verification, and future-module guardrails. Billing,
payment, and WhatsApp skills are planning guidance only until their phases are
explicitly approved.

## Acceptance criteria

1. All 12 requested directories exist below `.agents/skills`.
2. Every `SKILL.md` has matching `name` frontmatter and a useful `description`.
3. Phase 0 scope and RouterOS v6.49.13 limitations are explicit.
4. No skill instructs the agent to expose secrets or bypass tenant isolation.
5. Existing Superpowers and RPA skills remain intact.
6. The project baseline remains unchanged: no Laravel source is generated.