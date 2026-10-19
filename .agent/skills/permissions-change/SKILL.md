# Skill: Permissions Change

**Purpose:** Protect first registration, invitations, active administrators, module rights and record ownership. **Use when:** touching identity, authorization or role state.

**Context:** Read AGENTS.md, docs/architecture/overview.md, existing policies, relevant migrations, controller/service paths and tests.

**Procedure:**
1. Identify caller, target, allowed roles and data owners for each action.
2. Ensure setup is one-time and concurrency-safe; first account is an active admin.
3. Accept **email alone** from inviter. Store normalized unique email and a hashed, single-use expiring token; verify token and recipient email on claim.
4. For every admin demotion, suspension or deletion lock a shared database row and count remaining ACTIVE admins within the same transaction.
5. Check module enablement + member capability + record ownership on backend.
6. Test races, token expiry/replay, duplicate accounts, private data, insufficient permissions and negative API paths.

**Validation:** Run Pest security/feature tests, backend static checks, and affected end-to-end flows.

**Expected output:** Permission matrix and exact test evidence.

**Constraints:** Never allow zero active admins or broader access merely because user is an admin.

**Completion:** All relevant invariants are enforced by backend logic and negative tests.
