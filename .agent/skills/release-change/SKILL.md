# Skill: Release and Dependencies

**Purpose:** Keep reproducible, tested versioned releases and dependency maintenance. **Use when:** editing Dependabot, release automation, changelog, GHCR or tags.

**Context:** AGENTS.md, workflows, lockfiles, CI status and release issues.

**Procedure:**
1. Identify modified Composer, Bun, Docker or GitHub Actions dependencies and potential breaking changes.
2. Keep weekly Dependabot review with compatible grouping; update actual manifest paths.
3. Apply Semantic Versioning, with pre-1.0 version policy and CHANGELOG.
4. Build versioned OCI images and verify amd64/arm64 availability if publishing.
5. Gate publishing and merging on required CI/approvals, and document migration/rollback.

**Validation:** CI config syntax, image builds, version/tag checks and release smoke as applicable.

**Expected output:** Versioned deliverables, reproducible build evidence and security caveats.

**Constraints:** Never auto-merge unreviewed dependency changes or expose private config.

**Completion:** Release is reproducible, reviewable and documented.
