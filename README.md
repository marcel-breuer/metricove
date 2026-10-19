# Metricove

**An open-source, self-hosted home for household metrics, everyday organization, and AI-assisted insights.**

> **Status:** Planning and repository bootstrap. Metricove is **not yet runnable**; Docker images and installation instructions will arrive with the implementation.

Metricove is designed for a **single household per installation**. Members can track utility meters, vehicles, expenses, custom measurements, recipes, meals, pantry inventory, and shopping lists. Household administrators enable modules and manage who may use them.

## Product principles

- **One installation, one household.** Independent installations have independent databases and accounts.
- **Multiple household members.** The first registered account is an administrator; admins invite users **by email address only**. A household always has **at least one active administrator**.
- **Modular features.** Admins enable modules installation-wide and grant module permissions per user. Disabled modules keep their existing data but expose no functionality via UI, API, jobs, or AI tools.
- **Respect privacy.** Private user records remain private from other household members, including administrators, unless explicitly shared.
- **AI without lock-in.** A permission-aware assistant and MCP tools will support optional local models and optional external providers. AI writes require user approval.
- **Self-host first.** A reproducible Docker Compose deployment and documented backup/restore procedures are release requirements.
- **Open contributions.** Both humans and coding agents follow the same documented conventions.

## Planned modules

| Module | Planned capabilities |
| --- | --- |
| **Core (always enabled)** | First-run setup, account security, email-only invitations, member/admin management, module registry and permissions |
| Tracking | User-defined trackers, fields, units, historical readings |
| Utilities | Electricity, water, gas, meter readings and usage calculations |
| Vehicles | Odometer readings, servicing, insurance and operating costs |
| Finance | Spending, income, budgets and recurring expenses; banking integrations later |
| Recipes | Recipes, ingredients, servings, instructions, categories and favorites |
| Meal Planning | Weekly breakfast/lunch/dinner plans using recipes or free-text meals |
| Pantry | Grocery inventory, quantities, storage locations and best-before dates |
| Shopping | Shared shopping lists, manual items and optional ingredient suggestions |
| Analytics | Charts and comparisons for enabled and authorized modules |
| Import | CSV import/export and assisted meter-photo recognition |
| AI | In-app assistant, model adapters and permission-aware MCP tools |
| Health (future) | Opt-in, strictly private personal health and fitness metrics |

Modules should integrate with each other **without unnecessary hard dependencies**. For example, Shopping must work without Recipes, and Meal Planning must support meals entered without a saved recipe.

## Planned technology

- **Backend:** Laravel / PHP, API-first modular monolith
- **Frontend:** SvelteKit, TypeScript and Tailwind CSS; accessible, responsive UI
- **Storage:** PostgreSQL, persistent media files; Redis for queues/cache as needed
- **Runtime:** Multi-stage Docker builds, Server Side Up PHP, Bun and Nginx gateway.
- **Deployment:** Docker Compose and optional Coolify integration. Each installation defines its own private deployment address.

See [architecture](docs/architecture/overview.md) and [module contract](docs/modules/README.md) for design decisions. **No container image is published yet.**

## Contributing

We welcome bug reports, feature proposals, documentation, tests and code contributions. Start with [CONTRIBUTING.md](CONTRIBUTING.md). Development tasks, automated testing and release work are managed in [GitHub Issues](https://github.com/marcel-breuer/metricove/issues).

**AI coding agents:** Read the canonical [AGENTS.md](AGENTS.md) first, then load only relevant [shared skills](.agent/skills/README.md). Every change must respect access controls, modularity and test requirements.

## Project status and licensing

Metricove is published under the [MIT License](LICENSE). Community contributions are welcome under the policies in [CONTRIBUTING.md](CONTRIBUTING.md).

Release conventions will use SemVer, changelogs, CI checks and reproducible multi-architecture images. Track decisions and work in GitHub Issues.
