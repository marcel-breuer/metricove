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

## Core module registry and activation

The versioned registry in `apps/api/config/modules.php` defines stable keys, labels, descriptions, versions, required module keys and whether a module is always enabled. `core` is always available and is not stored as an optional state. Optional modules default to disabled until an administrator enables them. The registry rejects missing dependencies, self-dependencies and dependency cycles during application boot. Current optional modules require only Core; optional integrations remain independent, including Recipes, MealPlanning, Pantry and Shopping.

The Core module settings API is:

- `GET /api/admin/modules` returns the registered catalog, enabled state, unmet requirements and enabled dependents to active administrators.
- `PATCH /api/admin/modules/{key}` accepts only `enabled: boolean`. Enabling with an unmet requirement returns `422` and its module keys. Disabling a required module while a dependent is enabled returns `409` and the dependent keys. Disabling Core returns `409`.
- `GET /api/modules` returns household `enabled_modules` and an `available_modules` navigation catalog for the signed-in member. The latter contains only enabled optional modules with at least one exact `view`, `create`, `edit` or `manage` grant, and includes that member's capability flags. Core administration stays separate from optional modules.

Changes lock the singleton `household_settings` row, recheck the administrator from the database and write only the requested `module_states` row in the same transaction. Disable and re-enable do not delete or migrate module-owned records. An absent state row means disabled; Core is always returned as enabled.

Every optional API operation must use `module.access:{key},{capability}` and call `ModuleAccessService::authorize($actor, $key, $capability, $resource)` before accessing a record. This single check requires an active module, an exact member capability and, for private resources, record ownership or an explicit record-level grant. Do not use `module.enabled:{key}` alone for member data operations. User-initiated queue jobs must include `App\Jobs\Middleware\EnsureModuleAccess` with the initiating member ID, module key and capability so access is checked again when the job runs. Typed AI/MCP tools must call the same `ModuleAccessService` with the current member, capability and resource; `ModuleStateService::assertEnabled($key)` alone is insufficient. SvelteKit module navigation must be derived from `GET /api/modules`; navigation is advisory and server checks remain authoritative. No optional feature routes, jobs or AI/MCP tools are registered yet; those feature issues must apply these gates when adding them.

## Per-member module rights and private records

`module_user_permissions` stores independent `view`, `create`, `edit` and `manage` grants per member and optional module. Grants default to false and remain stored while a module is disabled. An active administrator manages them through `GET /api/admin/members/{member}/module-permissions` and `PUT /api/admin/members/{member}/module-permissions/{moduleKey}`. Changes are audited without email addresses or private record values. Core permissions continue to use Core account roles and are not editable in this matrix.

Administrator status grants access to member and module administration only. It does not grant optional-module capabilities or access to another member's private records. `manage` is an exact module capability; it does not imply `view`, `create`, `edit` or private-record access.

Any module model used in an authorization decision must implement `App\Contracts\ModuleResource` and declare its module key, nullable owner user ID and private/shared state. A private record must have an owner. The owner must still hold the required module capability. Another member, including an administrator, must hold both that module capability and an explicit `resource_user_permissions` grant for the exact record and operation. Only the active record owner with module-level `manage` permission may issue or revoke those grants. Models with persisted resource grants must use `App\Models\Concerns\HasResourcePermissions` so deleting a record removes its grants. Shared records still require the member's exact module capability.

The UI presents capability checkboxes per member and module. The authenticated app shell uses `available_modules` for responsive navigation and uses the same API state for module links; hidden navigation does not authorize operations. REST, jobs and AI/MCP must call `ModuleAccessService` at the operation boundary. A resource that does not declare its ownership policy fails closed when passed to that service.

The current SvelteKit shell provides the responsive household dashboard, member and module administration navigation, account links, a keyboard-accessible mobile drawer and Quick Add empty state. Tracking has its own schema builder, quick-entry form and private measurement history. Other module landing screens remain placeholders until their domain issues add records and entry forms; Quick Add does not create or imply saved data on its own.

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

## Tracking module contract

Tracking supports private trackers owned by one member and household templates managed by an active administrator. A household template exposes its schema to members with Tracking `view` access; every measurement still belongs to its author and stays private unless the owner grants record-level access. Administrator status alone does not reveal private tracker definitions or measurements.

- `GET /api/tracking/trackers` lists definitions visible to the member and requires `view`.
- `POST /api/tracking/trackers` creates a private tracker with `create`, or a household template with active-admin status and exact `manage` access. A schema has 1–50 fields of type `number`, `text`, `date` or `boolean`.
- `GET /api/tracking/trackers/{tracker}` returns its schema and up to 20 immutable schema revisions; `PUT` updates the schema with exact `manage` access.
- `GET /api/tracking/trackers/{tracker}/entries` returns the latest 100 visible entries and requires `view`.
- `POST /api/tracking/trackers/{tracker}/entries` records an entry with exact `create` access. `PATCH /api/tracking/entries/{entry}` edits an accessible entry with exact `edit` access and ownership or an explicit record grant.

Numeric values are accepted as decimal strings, limited to 14 whole and 6 fractional digits, and persisted as `DECIMAL(20,6)`. Every numeric field requires an explicit unit. Values include label, type and unit snapshots so historical display survives field retirement. Once a field has values, its type and unit cannot change; replace it with a new field. Schema changes increment the version and append an immutable snapshot. Entry writes carrying a stale schema version return `409 Conflict` and require a reload.

## Utilities module contract

Utilities records cumulative electricity, gas and water meter values. Private meters are the default and belong to their creator. Only an active household administrator with exact Utilities `manage` access can create a household meter; its readings are shared with members who have the exact required module capability. Administrator status alone does not reveal a private meter, its readings, correction history or photos. A meter photo is stored on the API's private local disk, is never returned as a storage path or public URL, and is streamed only through an authenticated route after the same meter and reading `view` checks.

Meter type fixes the register unit: `electricity` uses `kWh`, `gas` and `water` use `m³`. Clients cannot submit an arbitrary unit or silently convert readings. Values and optional interval alert thresholds are non-negative decimal strings with at most 14 whole and 6 fractional digits, persisted as `DECIMAL(20,6)`. A reading date is unique per meter. Readings can be entered out of order only when the cumulative value remains between the immediately preceding and following chronological readings. Every correction requires a reason and appends an immutable before/after revision in the same transaction. The meter row is locked before adjacent readings are checked and changed, so concurrent writes through the service cannot invalidate the ordering; the unique date index remains the database backstop.

Consumption is derived from the difference between adjacent visible readings; it is not stored separately. The API returns at most the latest 200 readings in chronological order, their derived interval consumption and elapsed days. If a member cannot view an adjacent private reading, the API does not calculate across that hidden value. An optional meter threshold marks an interval when its derived consumption is strictly greater than the configured amount; the warning is displayed in the meter history and trend chart. It is an interval warning, not a scheduled notification. Reading photos accept JPEG, PNG or WebP up to 5 MB and require Utilities `view` plus ownership or the exact record grant. Corrections and meter settings require exact `edit` or `manage` capability respectively; those capabilities do not imply `view`, `create` or each other.

The REST surface is `GET/POST /api/utilities/meters`, `GET/PATCH /api/utilities/meters/{meter}`, `POST /api/utilities/meters/{meter}/readings`, `PATCH /api/utilities/readings/{reading}`, and `GET /api/utilities/readings/{reading}/revisions` or `/photo`. Meter creation uses exact `create` for private meters and exact `manage` plus active-admin status for household meters. Every endpoint checks that Utilities is enabled and authorizes the exact module capability and resource at the service boundary.

## Cross-module rule
A shared integration must not make an optional module mandatory. Shopping works when Recipes, Pantry and MealPlanning are disabled. MealPlanning supports free-text dishes. Disablement removes access to integrations without deleting data, and re-enabling restores authorized use.
