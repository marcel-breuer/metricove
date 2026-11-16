# REST API guide

Metricove exposes a first-party JSON REST API under `/api`. The web app uses exactly this API; everything a member can do in the UI is available here, with the same server-side authorization. The machine-readable contract is [`openapi.yaml`](openapi.yaml) (OpenAPI 3.1). A running installation also serves an interactive reference at `/docs/api` and the JSON document at `/docs/api.json` to signed-in members.

AI agents and other automation should use the [MCP server](../mcp/README.md). It reuses the same services and policies and adds token scopes, human approval and audit for every write.

## Authentication and CSRF

The API authenticates with the encrypted session cookie issued by `POST /api/auth/login`. There are no REST bearer tokens: a REST write would bypass the approval and audit rules that apply to automation (see [exclusions](#intentional-exclusions)).

1. `GET /api/auth/session` sets the `XSRF-TOKEN` cookie and returns the session state.
2. `POST /api/auth/login` with `{"email", "password"}` (throttled).
3. Send the session cookie and, for every `POST`, `PUT`, `PATCH` and `DELETE`, an `X-XSRF-TOKEN` header with the URL-decoded value of the `XSRF-TOKEN` cookie.
4. Send `Accept: application/json`.

```sh
curl -c jar -b jar -s https://metricove.example/api/auth/session >/dev/null
TOKEN=$(awk '$6=="XSRF-TOKEN"{print $7}' jar | python3 -c 'import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))')
curl -c jar -b jar -H "X-XSRF-TOKEN: $TOKEN" -H 'Accept: application/json' \
  -H 'Content-Type: application/json' -d '{"email":"me@example.com","password":"..."}' \
  https://metricove.example/api/auth/login
curl -b jar -H 'Accept: application/json' 'https://metricove.example/api/finance/transactions?month=2026-10'
```

Use placeholders for credentials in scripts and keep them out of logs.

## Authorization model

- Core endpoints (profile, reminders, modules, AI assistant, admin) need an active member; `admin/*` additionally needs an active administrator.
- Optional module endpoints use `module.access:{module},{capability}` where capability is `view`, `create`, `edit` or `manage`. A disabled module answers `404`; a missing capability answers `403`.
- Private records are visible only to their owner or an explicit grant, also for administrators. A record you may not see answers `404`.
- The OpenAPI operations carry `x-metricove-module` (`key`, `capability`) and `x-metricove-admin-only` extensions that mirror these checks. A capability of `resource-checked` means the service verifies the exact capability against the record, for example shared versus private.
- Client-side hiding of navigation is advisory; the server enforces everything.

## Conventions

| Topic | Convention |
| --- | --- |
| Paths | `/api/{module}/{resource}[/{id}[/{sub-resource}]]`, kebab-case module names such as `meal-planning` |
| Verbs | `GET` read, `POST` create or action, `PATCH` partial update, `PUT` replace or idempotent set, `DELETE` remove |
| Versioning | The API is versioned with the application release (see [CHANGELOG](../../CHANGELOG.md)); paths carry no `/v1` segment. Breaking changes are announced in release notes. |
| Bodies | JSON. File uploads (receipts, photos, invoices, import files) use `multipart/form-data`. |
| Money and quantities | Decimal strings (`"12.50"`), never floats. Currencies are ISO 4217 codes, dates `YYYY-MM-DD`, timestamps ISO 8601 UTC. |
| Pagination | List endpoints that can grow are paged: `page` (1-based) for notes and receipts, or cursor parameters (`limit` up to 50 with `before_id` plus the date cursor named in the endpoint) for tracker entries and vehicle histories. Responses state whether more data exists. |
| Filtering | Query parameters per endpoint, e.g. `month=YYYY-MM`, `week_start` (a Monday), `search`, `status`, `tag`. Unknown filters are ignored; invalid values answer `422`. |
| Optimistic concurrency | Records that several members can edit carry an integer `version`. Send it back as `expected_version`; a mismatch answers `409`. |
| Idempotency | Confirm-style imports and receipt confirmations are bound to a server-issued preview or run id and cannot be applied twice. Setting operations (`PUT`, checked state, favorites) are naturally idempotent. |
| Rate limits | Sensitive endpoints (login, password, invitations, member and module management, assistant, MCP) are throttled; excess requests answer `429`. |

### Error envelope

All errors are JSON with a human-readable `message`; validation errors add `errors`.

```json
{ "message": "The given data was invalid.", "errors": { "amount": ["Enter a positive transaction amount."] } }
```

| Status | Meaning |
| --- | --- |
| 401 | No valid session |
| 403 | Missing capability or role, or an inactive member |
| 404 | Not found, not visible to you, or module disabled |
| 409 | Version conflict or state conflict (for example, a task already completed) |
| 422 | Validation failed |
| 429 | Rate limited |

Error messages are written to avoid secrets and other members' data.

## Endpoint reference

Every operation, with request fields and validation rules, is in [`openapi.yaml`](openapi.yaml) and the live `/docs/api`. This table lists the resource families.

| Area | Base path | Notes |
| --- | --- | --- |
| Setup and auth | `/api/setup`, `/api/auth/*`, `/api/invitations/claim` | First-run setup, login, logout, password reset |
| Profile | `/api/profile`, `/api/profile/password`, `/api/profile/mcp/*` | Preferences, password change, MCP tokens and approvals |
| Modules | `/api/modules` | Modules available to the current member |
| Reminders | `/api/reminders`, `/api/reminders/preferences` | Personal reminders |
| AI | `/api/ai/assistant`, `/api/ai/context` | In-app assistant and personal context entries |
| Administration | `/api/admin/*` | Invitations, members, module permissions, modules, settings, audit |
| Tracking | `/api/tracking/trackers`, `/api/tracking/entries/{id}` | Typed schemas and entries |
| Utilities | `/api/utilities/meters`, `/api/utilities/readings/{id}` | Meters, readings, revisions |
| Vehicles | `/api/vehicles`, `/api/vehicles/{id}/{odometer-readings,maintenance,insurance,costs,history/{type}}` | Odometer, maintenance, insurance, costs |
| Finance | `/api/finance/{overview,transactions,categories,budgets,recurring}` | Money as decimal strings |
| Receipts | `/api/receipts` | Upload preview, confirm, link to finance or vehicle records, sharing |
| Recipes | `/api/recipes` | Recipes, favorites, photos, URL import preview |
| Meal planning | `/api/meal-planning` | Weekly slots, copy previous week |
| Shopping | `/api/shopping/lists`, `/api/shopping/items/{id}` | Lists, items, history, meal suggestions |
| Pantry | `/api/pantry` | Stock, adjustments, movements |
| Tasks | `/api/tasks` | Tasks, completion, history, checklist items |
| Notes | `/api/notes`, `/api/notes/folders` | Private notes, folders, preview |
| Analytics | `/api/analytics/*` | Catalog, dashboard, personal widgets |
| Import | `/api/import/*` | CSV and photo imports with preview and confirm |
| Health | `/api/health` | Unauthenticated liveness |

## Examples

Create a household task and complete a checklist item (both need the `tasks` capability and the current `version`):

```sh
curl -b jar -H "X-XSRF-TOKEN: $TOKEN" -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"title":"Service the boiler","visibility":"household","timezone":"Europe/Berlin","priority":"medium"}' \
  https://metricove.example/api/tasks
curl -b jar -X PATCH -H "X-XSRF-TOKEN: $TOKEN" -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"completed":true,"expected_version":3}' \
  https://metricove.example/api/tasks/42/checklist-items/7/completion
```

A disabled module or an invisible record answers `404` with a JSON `message`.

## Intentional exclusions

REST has no gaps against the UI. These capabilities are deliberately absent from MCP, not from REST:

- Authentication, setup, password and invitation flows, member, module and permission administration, token management and approvals. An agent must never grant itself access or approve its own changes.
- AI assistant conversation and personal AI context. An agent would be steering the assistant that governs agents.
- Receipt, meter-photo, invoice, attachment and CSV uploads and original-file downloads. MCP calls carry no file uploads and must not exfiltrate stored documents.
- Receipt sharing and relinking, which change who can see a record.
- Recipe URL import preview and AI meal suggestions, which fetch external content or call an AI provider.

## Keeping the contract current

`docs/api/openapi.yaml` is generated by [Scribe](https://scribe.knuckles.wtf) from the Laravel routes and validation rules. After changing routes or request validation, run:

```sh
cd apps/api && php artisan api:openapi
```

`OpenApiContractTest` fails when a route is added, removed or changed without regenerating the contract.
