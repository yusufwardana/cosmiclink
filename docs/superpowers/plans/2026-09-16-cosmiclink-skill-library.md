# CosmicLink Skill Library Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a validated project-local CosmicLink skill library under `.agents/skills`.

**Architecture:** Use one focused `SKILL.md` per domain skill with explicit
triggers, constraints, workflow, and validation rules. Keep external skills and
the future Laravel application separate from this documentation library.

**Tech Stack:** Markdown, YAML frontmatter, PowerShell validation; no runtime dependencies.

**Spec:** `docs/superpowers/specs/2026-09-16-cosmiclink-skill-library-design.md`

## Global Constraints

- Canonical discovery root: `C:\xampp\htdocs\cosmiclink\.agents\skills`.
- Current hardware target: MikroTik RouterOS v6.49.13.
- Phase 0 uses `FakeNetworkDriver`; real RouterOS communication is out of scope.
- Do not generate Laravel source files while the baseline is absent.
- Do not expose secrets or weaken tenant isolation.

---

### Task 1: Create the skill library

**Files:** Create the 12 requested directories and their `SKILL.md` files under `C:\xampp\htdocs\cosmiclink\.agents\skills`.

- [ ] Create each focused skill with matching frontmatter.
- [ ] Include Phase 0 and security boundaries where relevant.
- [ ] Keep billing, payment, and WhatsApp guidance future-scoped.

### Task 2: Document and clean metadata

**Files:** Modify `C:\xampp\htdocs\cosmiclink\docs\AGENT_WORKFLOW.md`; modify `C:\xampp\htdocs\cosmiclink\skills-lock.json`.

- [ ] Record the canonical root and the 12 skills.
- [ ] Remove stale `tikoci/routeros-skills` lock entries.
- [ ] Preserve Superpowers and RPA entries.

### Task 3: Validate

**Files:** Read all created `SKILL.md` files and validate the lockfile.

- [ ] Confirm exactly 12 CosmicLink skill directories.
- [ ] Confirm each frontmatter has matching `name` and non-empty `description`.
- [ ] Confirm no stale RouterOS repository reference remains.
- [ ] Confirm no Laravel source was generated.