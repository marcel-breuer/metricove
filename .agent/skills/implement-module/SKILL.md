# Skill: Implement Module

**Purpose:** Build a self-contained, admin-toggleable module. **Use when:** working on a module feature or registry. **Do not use when:** only changing prose/docs.

**Context:** Read AGENTS.md, docs/modules/README.md, nearest module providers, routes, policies, UI and tests.

**Procedure:**
1. Define module key, capability map, schema and optional dependencies.
2. Implement typed Laravel business services, validation, policies, persistence and routes.
3. Add SvelteKit accessible responsive screens and capability-based menu integration.
4. Ensure disabled state blocks backend/API/queue/AI even when URL is called directly.
5. Add authorized, denied and disabled-state regression tests, plus data persistence tests.
6. Update public module contract docs and related issue.

**Validation:** Run targeted backend/frontend tests and static checks; report actual commands and results.

**Expected output:** Functional module, documented contract, test evidence and identified risks.

**Constraints:** A module must not depend unconditionally on another optional module.

**Completion:** Authorized users can use the enabled module; nobody can access it while disabled.
