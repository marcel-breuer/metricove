# Optional module contract

## Scope
The always-enabled Core owns accounts, household setup, invitation identity, admin status, module states, access permissions and audit. Feature modules are versioned code bundled with the installation; the admin can enable or disable them without discarding existing data.

## Core first-run setup
`GET /api/setup/status` reports only whether first-run setup is available. `POST /api/setup` accepts the first member's display name, normalized email and confirmed password. The database contains one fixed household-settings row; a transaction locks and atomically claims it before creating the first active administrator. The successful response initializes a web session. The endpoint returns `409 Conflict` after setup, and the form is protected by Laravel's session and CSRF middleware. No public account-registration endpoint remains open after this one-time flow.

## Core authentication and member profile
The current Core authentication surface is same-origin and session-based:

- `GET /api/auth/session` returns the signed-in member's public profile or `user: null`; it also initializes the CSRF cookie.
- `POST /api/auth/login` accepts a normalized email and password, regenerates the session on success and rate-limits attempts by IP and normalized email.
- `POST /api/auth/logout` invalidates the session and rotates the CSRF token.
- `GET /api/profile` and `PATCH /api/profile` require an authenticated session. Members can change only their display name and email; email uniqueness is case-insensitive and whitespace-normalized in both application validation and the database.
- `POST /api/auth/password/email` returns the same response for known and unknown addresses. Reset links expire, are throttled per member, and the stored token is hashed. The token is delivered in the URL fragment so it is not sent to the web server in the initial page request; the frontend removes it from the address bar before submitting it to the API.
- `POST /api/auth/password/reset` accepts a valid, unexpired token once and applies the configured minimum password length. Both reset operations are rate-limited by IP.

Session cookies are encrypted, `HttpOnly`, `SameSite=Lax`, and `Secure` when `APP_URL` uses HTTPS unless explicitly overridden. Per-installation SMTP settings deliver reset messages; the default mailer is SMTP, not a log transport, so reset links are not written to application logs. Public registration remains closed after initial setup.

## Core invitations
Only an active household administrator can manage invitations. `POST /api/admin/invitations` accepts a normalized email address only; `GET /api/admin/invitations` lists unclaimed invitations, including expired ones so they can be resent. `POST /api/admin/invitations/{id}/resend` rotates the token and expiry, invalidating any earlier link, and `DELETE /api/admin/invitations/{id}` revokes the invitation.

Invitation tokens are random and stored as SHA-256 digests. They expire after seven days by default (`AUTH_INVITATION_EXPIRE` can override the lifetime in minutes) and are delivered through the installation's configured SMTP mailer. The link carries the token in a URL fragment; the web page removes it from the address bar before posting it to `POST /api/invitations/claim`. Claiming creates an active non-admin account whose normalized email comes only from the invitation record, marks that address verified, accepts the invitation atomically, regenerates the session and signs in the recipient. Invalid, expired, revoked, replayed and already-registered claims receive the same generic response. Delivery failures do not log recipient addresses or tokens; the pending invitation remains available for resend.

## Core member administration
`GET /api/admin/members` lists active and suspended members for active administrators. `PATCH /api/admin/members/{id}` accepts only `is_admin` and/or `is_active`; `DELETE /api/admin/members/{id}` removes an account. An administrator may promote, demote, activate, suspend or remove any member, including themselves when another active administrator remains. The API rejects changes that would demote, suspend or remove the final active administrator with `409 Conflict`.

All authenticated API routes require an active member. Suspending an account also invalidates its web session on the next request; it cannot keep using profile or future protected endpoints with an existing cookie.

Every access change and removal is recorded in `household_audit_events` in the same transaction as the member change; no API endpoint edits or deletes audit events. `GET /api/admin/member-audit` returns the latest 100 events. Audit details contain only role/status flags and numeric member IDs, never email addresses, passwords or other profile fields. Member mutations acquire the singleton `household_settings` row lock and recount active administrators inside that transaction; every role/status/removal path must use this service so competing requests cannot remove the final admin.

If an operator must recover an existing account after out-of-band damage, run `php artisan metricove:recover-admin <user-id>` in the API container. This command can reactivate and promote only an existing member and records the recovery in the same audit table. It does not create accounts or expose an HTTP recovery endpoint.

## Registry and permissions
Every module defines:
- Stable `module_key`, label and version.
- Optional integration dependencies and sensible standalone fallbacks.
- Backend provider, routes, policies, services, schema and queue integrations.
- SvelteKit routes/navigation, forms, empty/error states and responsive layouts.
- Capabilities (`view`, `create`, `edit`, `manage`) resolved against per-member grants.
- Explicit AI/MCP typed tool registration and required user confirmation for writes.
- Tests for enabled/disabled operation, private record ownership and data preservation.

A request is allowed only if module enabled **and** user has permission **and** target resource is visible. Enforce this at Laravel service/API/tool boundaries; UI state is advisory.

## Domains
- `tracking`: customizable fields, units, typed entries and chart history.
- `utilities`: multiple meters and readings, historical usage.
- `vehicles`: mileage, upkeep, insurance and costs.
- `finance`: income/outgoings, categories, budgets and recurring charges.
- `recipes`: recipes, ingredients, steps, categories and servings.
- `meal_planning`: weeks with meal slots, saved recipes or free-text meals.
- `pantry`: inventory quantities, storage, expiry and stock events.
- `shopping`: lists and independently manageable items.
- `analytics`, `import`, `ai`: cross-cutting tools, gated by source-module permissions.
- `health` (future): private and separately consented data.

## Cross-module rule
A shared integration must not make an optional module mandatory. Shopping works when Recipes, Pantry and MealPlanning are disabled. MealPlanning supports free-text dishes. Disablement removes access to integrations without deleting data, and re-enabling restores authorized use.
