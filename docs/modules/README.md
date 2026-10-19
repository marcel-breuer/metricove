# Optional module contract

## Scope
The always-enabled Core owns accounts, household setup, invitation identity, admin status, module states, access permissions and audit. Feature modules are versioned code bundled with the installation; the admin can enable or disable them without discarding existing data.

## Core first-run setup
`GET /api/setup/status` reports only whether first-run setup is available. `POST /api/setup` accepts the first member's display name, normalized email and confirmed password. The database contains one fixed household-settings row; a transaction locks and atomically claims it before creating the first active administrator. The successful response initializes a web session. The endpoint returns `409 Conflict` after setup, and the form is protected by Laravel's session and CSRF middleware. No public account-registration endpoint remains open after this one-time flow.

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
