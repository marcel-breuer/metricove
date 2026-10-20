# Contributing to Metricove

Metricove is an MIT-licensed, community-friendly project. Contributions to code, documentation, tests and accessibility are welcome.

## Before you begin
- Read [README.md](README.md), [AGENTS.md](AGENTS.md), the [architecture overview](docs/architecture/overview.md) and any relevant [skills](.agent/skills/README.md).
- Search [issues](https://github.com/marcel-breuer/metricove/issues). Open an issue before implementing significant new features or changing public module contracts.
- Never include credentials, private instance details, personal data, or references to unrelated projects in commits, screenshots, issue bodies or PR descriptions.

## Branch and PR workflow
1. Work in a branch such as `feat/issue-N-description`, `fix/issue-N-description`, `test/issue-N-description` or `docs/issue-N-description`.
2. Use [Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/) (`feat:`, `fix:`, `test:`, `docs:`, etc.).
3. Create **one focused PR per issue**, describing behavior, implementation, tests, migration compatibility and risk.
4. Obtain reviews and green required CI before merge; never bypass protected branch rules.
5. Update docs and tests together with behavior changes. Use [SECURITY.md](SECURITY.md) for responsible vulnerability reporting.

## Architectural conventions
Laravel/PHP services and policies enforce authorization and all writes. Use typed methods, Laravel Form Requests, migrations, Pint, PHPStan and Pest. SvelteKit/TypeScript/Tailwind code should be strict, accessible and responsive, with clear loading, error and empty states. Use PostgreSQL-compatible decimal quantities and exact money representations. Keep optional modules independent; consult [module contract](docs/modules/README.md).

## Validation
Run the same quality gates locally that are reported on pull requests:

```sh
cd apps/api
composer install --no-interaction --no-progress --prefer-dist
composer test
composer pint:check
composer analyse
composer audit --locked

cd ../web
bun install --frozen-lockfile
bun test
bun run check
bun run --bun build
bun audit --audit-level=high

cd ../..
docker compose --env-file .env.example config --quiet
docker compose --env-file .env.example build --parallel
docker buildx build --platform linux/amd64 --target test --file apps/api/Dockerfile --load --tag metricove/api:test .
```

The PostgreSQL integration job runs the full backend suite on an isolated PostgreSQL 17 database, including the first-admin and last-admin concurrency regressions. For a local PostgreSQL run, configure the `DB_*` environment variables for a disposable test database, run `php artisan migrate:fresh --force --no-interaction` from `apps/api`, then run `composer test`. Never point this command at a personal or production database.

Pull requests are expected to pass **Backend quality**, **Frontend quality** (unit tests, Svelte/TypeScript check and build), **Security audit**, **PostgreSQL integration**, and **Validate Compose and build images** (including stack health and gateway/API smoke requests). Do not merge while any expected check is pending or failing, and do not bypass repository merge rules. The PHPStan baseline records existing diagnostics so new diagnostics fail the analysis gate; remove baseline entries as the underlying code is improved. Record the exact commands and results; do not invent passing status. Add regression tests for one-time setup, last-active-admin under concurrent actions, email-only invitations, module permission checks, privacy and AI tool behavior.

## Licensing
Metricove uses the [MIT License](LICENSE). By submitting a contribution, you agree it may be distributed under that license. Retain required copyright notices.
