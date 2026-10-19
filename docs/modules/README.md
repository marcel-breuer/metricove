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
