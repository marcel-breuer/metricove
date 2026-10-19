# Skill: Docker Change

**Purpose:** Reproducible Docker Compose installation. **Use when:** Dockerfiles, gateway, services, environment, healthchecks or image CI change.

**Context:** AGENTS.md, architecture overview, compose, Dockerfiles, config and healthchecks.

**Procedure:**
1. Keep one installation / one household and minimize publicly exposed ports.
2. Separate PHP API, SvelteKit Bun server, Nginx gateway, queue, scheduler, PostgreSQL and Redis.
3. Use multi-stage builds, minimal runtime permissions, immutable images and persistent volumes.
4. Keep actual hostnames, credentials and keys exclusively in private environment config.
5. Maintain healthy startup, safe migrations, graceful shutdown and backups.
6. Document generic installation, upgrade and rollback instructions.

**Validation:** `docker compose config`, builds, health checks, DB restart/persistence smoke test; report unrun checks honestly.

**Expected output:** Runtime changes, verified topology and operational risks.

**Constraints:** No hard-coded deployment environment or unrelated project attribution.

**Completion:** Validated, documented self-hosting without required external services.
