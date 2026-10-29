# Metricove — Repository Agent Rules

These instructions apply to humans and AI assistants (Codex, Copilot, Claude Code, etc.). This file is the **canonical repository-wide source of agent rules**. Tool-specific adapters reference it rather than duplicating rules. Read the closest local `AGENTS.md` if present, plus only the relevant [.agent/skills](.agent/skills/README.md).

## Product boundaries — do not violate
1. **Exactly one household per installation.** No multi-tenant accounts or multiple households per deployment.
2. **First registered user becomes an admin**, once, atomically. After setup, only invited users may register.
3. **At least one active admin always exists.** Multiple admins are permitted. Prevent removing, suspending, deactivating, or demoting the final active admin with a database-backed concurrency-safe invariant.
4. **Invitation identity is the email address only.** Admin supplies only email; an expiring, single-use invite is bound to recipient email; recipient chooses display name and password. Mail delivery uses per-installation SMTP configuration.
5. **Admin-controlled modules.** Core cannot be disabled. Optional modules are enabled household-wide and individually granted to members. Backend, frontend, jobs, REST and AI/MCP must enforce the same policy. Disabled modules preserve existing data.
6. **Private data stays private**, including against admins, unless explicit resource-level permission allows access. No sensitive values in logs, docs, tests or AI-provider prompts by default.
7. **AI is strictly permission-bound.** Only typed, registered server-side actions; no model-provided raw SQL or arbitrary command execution. User approval and audit required for writes.
8. **Food functionality is modular**: Recipes, MealPlanning, Pantry and Shopping are independently enabled. Shopping works alone; meal plans can use free-text dishes without Recipes.
9. **No private deployments in git.** Never commit actual internal hostnames, credentials, server paths or unrelated repository references. Use placeholders and runtime environment variables.

## Architecture
- Modular Laravel/PHP backend, REST API, Laravel Policies, PostgreSQL, Redis queues, versioned migrations.
- SvelteKit + TypeScript + Tailwind responsive web frontend; accessible and mobile-first.
- Self-hostable with Docker Compose; current topology: Laravel API, Bun web, Nginx gateway, worker/scheduler, PostgreSQL, Redis. Only the gateway publishes a host port.
- Code-shipped domain modules: Core, Tracking, Utilities, Vehicles, Finance, Receipts, Recipes, MealPlanning, Pantry, Shopping, Analytics and Import; Health considered later. The permission-aware AI assistant is a Core app feature, not a separately enabled module.
- See [architecture](docs/architecture/overview.md) and [module contracts](docs/modules/README.md). The API, web scaffold and Compose runtime are implemented; household setup and product modules are still being delivered incrementally. Do not claim unimplemented product behavior exists.

## Contribution workflow
1. Read the assigned GitHub Issue, acceptance criteria, current repository layout, applicable agent rules and 1–2 relevant skills.
2. Implement the smallest complete feature. Keep controller/API layers thin; put logic in services, policies and typed domain operations. Preserve module boundaries and maintainability.
3. Use Laravel/Pest, Pint, static analysis and SvelteKit typechecking/tests/build when those toolchains exist. Add authorization and negative tests; verify admin race conditions and disabled module behavior.
4. Use transaction-safe migrations and explicit units/decimal money. Preserve backup/upgrade compatibility.
5. Update contract docs for new module surfaces and user-visible setup changes.
6. Open **one issue-linked pull request**, assign the issue to the maintainer, report exact validation commands/results and known limitations. Merge only when branch protection and required checks pass.
7. Use Conventional Commits and Semantic Versioning. A separate GitHub Issue documents each release or testing change.

**Security review always wins over convenience.** No shortcuts by hiding navigation instead of enforcing server-side checks. Never assert unexecuted tests passed. For work on dependencies, containers, permissions, AI or modules, load the corresponding [skill](.agent/skills/README.md).
