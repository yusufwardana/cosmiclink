# Agent Workflow Integration

## What was integrated

CosmicLink now contains a project-local workflow adapter inspired by
[obra/superpowers](https://github.com/obra/superpowers):

- `AGENTS.md` is the workspace entry point.
- `.kilo/SUPERPOWERS_WORKFLOW.md` contains the operational rules.
- This document records the integration boundary and verification status.

The adapter emphasizes repository inspection, design approval, YAGNI, TDD,
systematic debugging, security review, scope control, and verification before
completion.

CosmicLink-specific skills use the canonical discovery root:

```text
C:\xampp\htdocs\cosmiclink\.agents\skills
```

The domain library contains:

- `cosmiclink-architecture`
- `laravel-development`
- `multi-tenancy`
- `database-design`
- `network-driver`
- `mikrotik-routeros`
- `billing-engine`
- `payment-webhook`
- `whatsapp`
- `security-audit`
- `testing`
- `phase-verification`

External RPA skills remain separate and are installed beside this library. The
RPA catalog's `mikrotik-config-gen` skill targets RouterOS v7.x, so it is not a
replacement for validated RouterOS v6.49.13 documentation.

## What was deliberately not done

- The upstream repository was not cloned into the application.
- No npm, PHP, or other runtime dependency was added.
- No global agent configuration was changed.
- No Laravel application was generated.
- No official Cline/Kilo Superpowers plugin integration was claimed.

Superpowers documents official harness integrations for several agents, but the
current upstream documentation does not list Cline or Kilo. The local adapter
therefore provides the workflow as readable project instructions; whether a
particular harness automatically discovers `AGENTS.md` is harness-dependent.

## Source reference

- Repository: <https://github.com/obra/superpowers>
- Upstream metadata version inspected: `6.3.0`
- License: MIT
- Relevant upstream concepts: `using-superpowers`, `brainstorming`,
  `writing-plans`, `test-driven-development`, `systematic-debugging`, and
  `verification-before-completion`.

## Current project baseline

At the time of integration, `C:\xampp\htdocs\cosmiclink` contained only the
`.kilo` directory and no `artisan`, `composer.json`, `app/`, `database/`,
`routes/`, or `tests/` directories. The Phase 0 Laravel implementation is
therefore still blocked pending restoration or creation of the intended Laravel
project baseline.

## RouterOS target

The intended router target is:

```text
MikroTik RouterOS v6.49.13
```

The installed `mikrotik-config-gen` skill from `EvilFreelancer/rpa-skills` is
scoped to RouterOS v7.x and does not cover RouterOS v6.49.13. Its guidance must
not be used as authoritative v6 documentation. In particular, do not assume
that the v7 REST API, `/console/inspect`, v7-only paths, or v7 scripting
behavior exist on v6.49.13.

For the eventual real integration, keep version-specific communication behind
the existing driver boundary. The likely future implementation is a dedicated
`RouterOsV6NetworkDriver` using the RouterOS API/API-SSL service, not the v7
REST API. This is intentionally not implemented in Phase 0.

The Phase 0 default remains the deterministic `FakeNetworkDriver`:

```text
NETWORK_DRIVER=fake
```

No real router credentials or physical RouterOS connection are required for
the current development phase.

## Maintenance

When upstream workflow guidance changes, review the upstream files and update
`.kilo/SUPERPOWERS_WORKFLOW.md` deliberately. Do not blindly copy upstream
content or introduce a runtime dependency for a documentation-only workflow.