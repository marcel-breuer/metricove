# Skill: Write Tests

**Purpose:** Add reliable regression and integration coverage. **Use when:** adding tests or when changing behavior.

**Context:** AGENTS.md, issue acceptance criteria, relevant modules, existing test config.

**Procedure:**
1. Identify positive/negative cases; add smallest stable tests.
2. Include first-setup race and at-least-one-active-admin concurrency tests.
3. Verify email-only invitation binding, expiry, replay and SMTP error handling.
4. Verify module enabled/disabled across UI, REST, jobs and MCP, with resource ownership.
5. Exercise food modules both standalone and integrated; protect database values and units.
6. Run targeted tests first, then impacted analysis, builds and Compose smoke.

**Validation:** State exact commands, actual pass/fail and known untested paths.

**Expected output:** Test coverage linked to the issue and reproducible commands.

**Constraints:** Never fake test results or bypass authorization in mocks.

**Completion:** Tests verify stated acceptance criteria, including failures and edge cases.
