# Metricove

**An open-source, self-hosted home for household metrics, everyday organization, and AI-assisted insights.**

> **Status:** Laravel API, SvelteKit web app, Docker Compose runtime and first-run household setup are implemented. Sign-in, invitations and product modules are still in progress.

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
- **Runtime:** Docker Compose runs the API, web, Nginx gateway, PostgreSQL, Redis, queue worker and scheduler. Only the gateway publishes a host port.
- **Deployment:** Docker Compose and optional Coolify integration. Each installation defines its own private deployment address.

See [architecture](docs/architecture/overview.md) and [module contract](docs/modules/README.md) for design decisions. Release versions and image operations are documented in [the release guide](docs/releases.md).

## Docker Compose

Docker Engine and the Docker Compose plugin are required. Prepare a private `.env` file with fresh application and database secrets, then build and start the stack:

```sh
./scripts/prepare-compose-env.sh
docker compose config --quiet
docker compose up -d --build
docker compose ps
```

The generated `.env` is excluded from Git. Set `APP_URL` to the public URL and set `SESSION_SECURE_COOKIE=true` when TLS is terminated by a reverse proxy. Open the web app at `http://localhost:8080`; the first-run administrator form is available at `/setup`. The API health endpoint is `/api/health` and the web health endpoint is `/health`.

PostgreSQL data, Redis queue data and Laravel application files use named Docker volumes. `docker compose down` preserves them; `docker compose down --volumes` deletes them. Back up the PostgreSQL and application-storage volumes before upgrades or destructive maintenance.

GitHub Actions publishes the API, web and gateway images to GHCR for `linux/amd64` and `linux/arm64` after the release commit's main-branch quality and Compose workflows pass. Tags omit the leading `v` (for example, `0.1.0`); version tags are published once, and each release records its immutable image digests. New GHCR packages default to private; make each package public in its GitHub package settings if installations should pull without registry credentials. Set `APP_IMAGE_TAG` in `.env` to the release version, then run `docker compose pull` and `docker compose up -d --wait`. Back up persistent data before upgrades and follow the [upgrade and rollback steps](docs/releases.md#upgrade-and-rollback). A local Compose build targets the current host architecture.

## Development

Run the API and web app independently from their directories. The API needs PHP 8.3+ and Composer; the web app needs Bun 1.4+.

```sh
cd apps/api
composer install
cp .env.example .env
php artisan key:generate
php artisan serve --host=0.0.0.0 --port=8000
```

In another terminal:

```sh
cd apps/web
bun install --frozen-lockfile
bun run dev --host 0.0.0.0
```

The API health checks are `/up` and `/api/health`; the web health check is `/health`. Run `composer test` in `apps/api`, then `bun run check` and `bun run --bun build` in `apps/web` to validate the scaffold. Data-backed API features require reachable PostgreSQL and Redis services configured through environment variables.

## Contributing

We welcome bug reports, feature proposals, documentation, tests and code contributions. Start with [CONTRIBUTING.md](CONTRIBUTING.md). Development tasks, automated testing and release work are managed in [GitHub Issues](https://github.com/marcel-breuer/metricove/issues).

**AI coding agents:** Read the canonical [AGENTS.md](AGENTS.md) first, then load only relevant [shared skills](.agent/skills/README.md). Every change must respect access controls, modularity and test requirements.

## Project status and licensing

Metricove is published under the [MIT License](LICENSE). Community contributions are welcome under the policies in [CONTRIBUTING.md](CONTRIBUTING.md).

Release conventions use pre-1.0 SemVer, a maintained changelog, successful CI and reproducible multi-architecture images. Track release work in GitHub Issues.
