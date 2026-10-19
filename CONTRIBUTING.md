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
Once the Laravel/SvelteKit/Docker scaffolds exist, use the documented project commands for backend unit/integration tests, static analysis, frontend typecheck/test/build and Docker Compose smoke tests. Record what ran; do not invent passing status. Add regression tests for one-time setup, last-active-admin under concurrent actions, email-only invitations, module permission checks, privacy and AI tool behavior.

## Licensing
Metricove uses the [MIT License](LICENSE). By submitting a contribution, you agree it may be distributed under that license. Retain required copyright notices.
