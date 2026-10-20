# Metricove architecture — proposed

> The API, web application, Docker Compose runtime, one-time household setup, session authentication, member profiles and password recovery are implemented. Invitations and domain modules are being delivered incrementally; see GitHub Issues and PRs for current status.

## Deployment and tenancy
A self-hosted installation represents **exactly one household**, with one independent PostgreSQL database and persistent file store. Multiple installations are supported but there is no multi-household tenancy in one deployment. The first sign-up is a one-time, atomic setup and creates an active administrator. Public registration stops after setup.

The backend is a Laravel modular monolith; the frontend is SvelteKit with Tailwind CSS. Docker Compose runs a gateway, API, web, queue worker, scheduler, PostgreSQL and Redis. Only the Nginx gateway publishes a host port; database, Redis, PHP-FPM and Bun stay on private Compose networks. Operators choose domains and credentials in private configuration.

## Administration and invitations
The household may have multiple active admins but **never fewer than one**. All role changes and user deactivations/removals must share a database transaction/lock so simultaneous requests cannot remove the last admin. Admin invitations require **only the email address**, normalized and unique, with a single-use expiring token bound to a verified recipient email. SMTP settings are per installation. The invited user selects their password and display name.

## Module capability model
`Core` (auth, members, settings, module permissions, audit) is always available. Each optional module declares a stable key, capabilities, migrations, API routes, frontend navigation and opt-in cross-module contracts. Administrators activate modules household-wide then grant module-level permissions per member. Every operation must validate enabled state + user capability + record visibility at the backend. Disabled modules retain existing data; they cannot be accessed by UI, REST, queues or AI/MCP tools.

Module list: Tracking, Utilities, Vehicles, Finance, Recipes, Meal Planning, Pantry, Shopping, Analytics, Import, AI; future Health. Health and other private records cannot be disclosed merely because a requester is an administrator.

## Food modules
- **Recipes:** ingredient quantities, servings, steps, tags/categories and optional pictures.
- **MealPlanning:** week/day/meal slots; optional recipe reference OR free-text dish.
- **Pantry:** private-by-default or admin-shared stock levels, explicit units, storage locations, best-before status and versioned manual movement history. Expiry notices are shown in-app; stock never changes automatically.
- **Shopping:** shared lists, items, check-off and optional imported ingredient suggestions.
Each module must operate independently where sensible. Cross-module interactions must check both modules' enabled states and caller permissions. Pantry stock cannot be silently changed by meal planning.

## AI architecture
An optional local or remote AI provider adapter supports narrow, typed domain tools. Laravel applies the same authorization logic regardless of UI, REST or MCP caller. No direct LLM-supplied SQL. Writes require preview, explicit human approval, idempotency and audit. Core operations do not require an AI provider.

## Data integrity and operations
Money and physical quantities are decimal with explicit currency/units. Schema changes are versioned Laravel migrations; background jobs are idempotent and verify active module state at execution time. Persistent database/media backups and restore drills, secure secrets, healthchecks and tested upgrades are release requirements.

## Decision log
- ADR-001: one installation / one household.
- ADR-002: admin-activated modules + separate user permissions.
- ADR-003: modules ship as reviewed code, not arbitrary runtime-installed PHP.
- ADR-004: first user admin; >=1 active admin protected under concurrent changes.
- ADR-005: reproducible multi-stage container packaging.
- ADR-006: open-source contribution and issue/PR standards.
- ADR-007: canonical AGENTS.md with shared skills.
- ADR-008: operator-specific deployment details stay out of version control.
