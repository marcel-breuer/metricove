# MCP tool catalogue

This is the complete list of tools, resources and prompts the Metricove MCP server can offer. The live source of truth for one token is the `tools/list` response, which hides everything the token or its owner may not use. Input schemas are strict (`additionalProperties: false`); unexpected arguments are rejected. A test fails when a registered tool is missing from this page.

Tool annotations: read tools set `readOnlyHint: true`; write tools set `readOnlyHint: false` and only prepare a change (see [approval flow](README.md#write-approval-flow)).

## Scopes

A scope is `{module}:{capability}`: `view`, `create`, `edit` or `manage`. Core scopes are `core:profile:view` and `core:reminders:view`. A token can only be created with scopes its owner currently holds, and every call re-checks the owner's live grants.

## Resources

| URI | Description |
| --- | --- |
| `metricove://api/http/openapi.json` | OpenAPI contract of the REST API (informational; the bearer token does not grant REST access) |
| `metricove://mcp/approval-workflow` | Short guide to the preview, approval and completion flow |

## Prompts

| Prompt | Description |
| --- | --- |
| `weekly_household_review` | Read-only review of reminders, tasks, meals, shopping, pantry and budgets |
| `propose_change_with_approval` | Guides an agent through preview, human approval and completion |

## Module snapshot reads

Bounded summaries backed by the same registry as the in-app assistant. Each requires `{module}:view` for the module named in the tool.

| Tool | Module |
| --- | --- |
| `summarize_finance_month` | Finance |
| `list_visible_financial_records` | Finance |
| `summarize_visible_tracker_entries` | Tracking |
| `summarize_visible_utility_readings` | Utilities |
| `summarize_visible_vehicle_odometer_readings` | Vehicles |
| `list_visible_recipes` | Recipes |
| `show_visible_meal_plans` | Meal Planning |
| `list_visible_pantry_items` | Pantry |
| `list_visible_shopping_lists` | Shopping |
| `show_visible_analytics` | Analytics |
| `list_visible_tasks` | Tasks |

## Fixed tools

| Tool | Scopes | Description |
| --- | --- | --- |
| `list_exportable_utility_readings` | `import:view` and `utilities:view` | Up to 50 utility reading rows in export order |
| `preview_utility_reading` | `utilities:create` and `utilities:view` | Prepare a utility reading for approval |
| `complete_approved_utility_reading` | `utilities:create` and `utilities:view` | Save an approved utility reading (idempotent) |
| `complete_approved_mcp_change` | any write scope | Complete any approved `preview_*` change (idempotent) |

### Read tools (registered domain reads)

| Tool | Module | Required scopes | Description |
| --- | --- | --- | --- |
| `show_current_member_profile` | Core | `core:profile:view` | Show the authenticated token owner’s basic profile and preferences. It never returns credentials or other members’ data. |
| `list_visible_reminders` | Core | `core:reminders:view` | List the token owner’s open reminders for visible tasks and records, honoring their reminder preferences. It never returns other members’ reminders. |
| `list_visible_vehicles` | Vehicles | `vehicles:view` | List vehicles currently visible to the member, including the ids needed by other vehicle tools. |
| `list_visible_vehicle_odometer_readings` | Vehicles | `vehicles:view` | List a bounded, cursor-based page of odometer readings for one authorized vehicle, newest first. |
| `list_visible_vehicle_records` | Vehicles | `vehicles:view` | List a bounded, cursor-based page of maintenance records, insurance policies or costs for one authorized vehicle. Invoice files are not returned. |
| `list_visible_utility_meters` | Utilities | `utilities:view` | List utility meters currently visible to the member, including the ids needed by other utility tools. |
| `show_visible_finance_overview` | Finance | `finance:view` | Show the visible income, expense and budget overview for one month. |
| `list_visible_finance_budgets` | Finance | `finance:view` | List monthly category budgets visible to the member, for the current month unless a month is given. |
| `list_visible_finance_recurring_entries` | Finance | `finance:view` | List recurring income and expense schedules visible to the member. |
| `show_visible_recipe` | Recipes | `recipes:view` | Show one authorized recipe with its ingredient list and preparation steps. |
| `list_visible_notes` | Notes | `notes:view` | List a bounded page of notes currently visible to the member, with optional search and filters. |
| `show_visible_note` | Notes | `notes:view` | Show one note, including its body, only when the member can view that resource. |
| `list_visible_note_folders` | Notes | `notes:view` | List folders owned by the authenticated member. |
| `list_visible_receipts` | Receipts | `receipts:view` | List a bounded page of receipts and invoice metadata currently visible to the member. Original files are not returned. |
| `list_meal_plan_week` | Meal Planning | `meal_planning:view`, `recipes:view` | Show a Monday-to-Sunday meal plan week. Recipe-linked slots also require Recipes view access. |
| `list_visible_tracker_schemas` | Tracking | `tracking:view` | List tracker definitions visible to the member, without private entry values. |
| `show_visible_tracker_schema` | Tracking | `tracking:view` | Show one authorized tracker definition and its bounded schema revision history. |
| `list_visible_tracker_entries` | Tracking | `tracking:view` | List a bounded page of authorized entries using the current typed tracker schema. |
| `list_visible_finance_categories` | Finance | `finance:view` | List finance categories currently visible to the member. |
| `show_visible_utility_meter` | Utilities | `utilities:view` | Show one authorized meter with its bounded readings and usage summary. |
| `list_visible_utility_reading_revisions` | Utilities | `utilities:view` | List the bounded audit history for one authorized reading. |
| `show_visible_vehicle` | Vehicles | `vehicles:view` | Show one authorized vehicle with bounded odometer, maintenance, insurance and cost records. |
| `list_visible_vehicle_odometer_revisions` | Vehicles | `vehicles:view` | List the bounded audit history for one authorized odometer reading. |
| `list_visible_pantry_movements` | Pantry | `pantry:view` | List the bounded stock movement history for one authorized pantry item. |
| `show_visible_shopping_list` | Shopping | `shopping:view` | Show one authorized shopping list and its currently visible items. |
| `list_visible_shopping_history` | Shopping | `shopping:view` | List the bounded activity history for one authorized shopping list. |
| `list_visible_task_assignees` | Tasks | `tasks:view` | List active household members who can be assigned to a visible task. |
| `list_visible_task_history` | Tasks | `tasks:view` | List the bounded completion history for one authorized task. |
| `list_visible_analytics_metrics` | Analytics | `analytics:view` | List only metrics enabled by the member’s current source module permissions. |

### Write tools (preview, human approval, completion)

Every tool below only prepares a change. Completion goes through `complete_approved_mcp_change`. A token needs the module scope for the listed capability or `manage` for the same module.

| Tool | Module | Capability | Description |
| --- | --- | --- | --- |
| `preview_create_vehicle` | Vehicles | `create` | Prepare a private or household vehicle for member approval. |
| `preview_update_vehicle` | Vehicles | `manage` | Prepare a vehicle detail change for member approval. |
| `preview_add_vehicle_maintenance` | Vehicles | `create` | Prepare a maintenance record for a visible vehicle for member approval. |
| `preview_add_vehicle_insurance` | Vehicles | `create` | Prepare an insurance policy for a visible vehicle for member approval. |
| `preview_add_vehicle_cost` | Vehicles | `create` | Prepare a cost entry for a visible vehicle for member approval. |
| `preview_create_utility_meter` | Utilities | `create` | Prepare a private or shared utility meter for member approval. |
| `preview_update_utility_meter` | Utilities | `manage` | Prepare a utility meter detail update for member approval. |
| `preview_create_recipe` | Recipes | `create` | Prepare a recipe for member approval. The recipe is saved only after approval in Metricove. |
| `preview_favorite_recipe` | Recipes | `edit` | Prepare a personal recipe favorite for member approval. |
| `preview_unfavorite_recipe` | Recipes | `edit` | Prepare removal of a personal recipe favorite for member approval. |
| `preview_create_meal_plan_slot` | Meal Planning | `create` | Prepare a free-text dish or recipe-linked meal for member approval. |
| `preview_update_meal_plan_slot` | Meal Planning | `edit` | Prepare changes to a meal plan slot for member approval. |
| `preview_create_pantry_item` | Pantry | `create` | Prepare a pantry item for member approval. |
| `preview_update_pantry_item` | Pantry | `edit` | Prepare a version-checked pantry detail update for approval. |
| `preview_create_shopping_list` | Shopping | `create` | Prepare a shopping list for member approval. |
| `preview_update_shopping_list` | Shopping | `edit` | Prepare a version-checked shopping list rename for member approval. |
| `preview_create_financial_transaction` | Finance | `create` | Prepare a private or household transaction for member approval. |
| `preview_create_finance_category` | Finance | `create` | Prepare a private or household category for member approval. |
| `preview_update_finance_category` | Finance | `edit` | Prepare a category rename for member approval. |
| `preview_delete_finance_category` | Finance | `edit` | Prepare deletion of an unused category for member approval. |
| `preview_create_finance_budget` | Finance | `create` | Prepare a monthly category budget for member approval. |
| `preview_update_finance_budget` | Finance | `edit` | Prepare a budget change for member approval. |
| `preview_delete_finance_budget` | Finance | `edit` | Prepare budget deletion for member approval. |
| `preview_create_finance_recurring_entry` | Finance | `create` | Prepare a private or household recurring entry for member approval. |
| `preview_update_finance_recurring_entry` | Finance | `edit` | Prepare a recurring entry update for member approval. |
| `preview_delete_finance_recurring_entry` | Finance | `edit` | Prepare recurring entry deletion for member approval. |
| `preview_record_finance_recurring_occurrence` | Finance | `create` | Prepare the current due occurrence as a transaction for member approval. |
| `preview_update_financial_transaction` | Finance | `edit` | Prepare a transaction update for member approval. |
| `preview_add_tracker_entry` | Tracking | `create` | Prepare a typed tracker entry for member approval. |
| `preview_create_tracker` | Tracking | `create` | Prepare a private or household typed tracker schema for member approval. |
| `preview_update_tracker_schema` | Tracking | `manage` | Prepare a version-checked schema change for member approval. |
| `preview_delete_tracker` | Tracking | `manage` | Prepare deletion of a tracker and its entry history for member approval. |
| `preview_update_tracker_entry` | Tracking | `edit` | Prepare a typed tracker entry update for member approval. |
| `preview_add_vehicle_odometer_reading` | Vehicles | `create` | Prepare an odometer reading for member approval. |
| `preview_create_analytics_widget` | Analytics | `create` | Prepare a chart from the member's currently permitted analytics catalog. |
| `preview_update_analytics_widget` | Analytics | `edit` | Prepare a version-checked personal analytics widget update for approval. |
| `preview_create_household_task` | Tasks | `create` | Prepare a private or shared task for member approval. |
| `preview_update_household_task` | Tasks | `edit` | Prepare a version-checked task update for member approval. |
| `preview_delete_household_task` | Tasks | `edit` | Prepare task deletion for member approval. |
| `preview_update_recipe` | Recipes | `edit` | Prepare recipe details and ingredients for member approval. |
| `preview_delete_recipe` | Recipes | `edit` | Prepare deletion of a recipe for member approval. |
| `preview_delete_meal_plan_slot` | Meal Planning | `edit` | Prepare removal of a meal plan slot for member approval. |
| `preview_adjust_pantry_stock` | Pantry | `edit` | Prepare a stock adjustment for member approval. |
| `preview_create_shopping_item` | Shopping | `create` | Prepare a shopping list item for member approval. |
| `preview_update_shopping_item` | Shopping | `edit` | Prepare a version-checked shopping item update for member approval. |
| `preview_move_shopping_item` | Shopping | `edit` | Prepare a version-checked shopping list reorder for member approval. |
| `preview_set_shopping_item_checked` | Shopping | `edit` | Prepare a reversible check-off for member approval. |
| `preview_delete_shopping_item` | Shopping | `edit` | Prepare removal of a shopping list item for member approval. |
| `preview_delete_financial_transaction` | Finance | `edit` | Prepare deletion of a visible financial transaction for member approval. |
| `preview_delete_tracker_entry` | Tracking | `edit` | Prepare deletion of a tracker entry for member approval. |
| `preview_correct_vehicle_odometer_reading` | Vehicles | `edit` | Prepare an odometer correction for member approval. |
| `preview_delete_analytics_widget` | Analytics | `edit` | Prepare removal of a personal analytics widget for member approval. |
| `preview_complete_household_task` | Tasks | `edit` | Prepare task completion for member approval. |
| `preview_correct_utility_reading` | Utilities | `edit` | Prepare a dated meter reading correction for member approval. |
| `preview_create_note` | Notes | `create` | Prepare a private note for member approval. |
| `preview_update_note` | Notes | `edit` | Prepare a version-checked note update for member approval. Sharing requires notes manage access. |
| `preview_archive_note` | Notes | `edit` | Prepare a version-checked archive or restore for member approval. |
| `preview_delete_note` | Notes | `edit` | Prepare deletion of an owned note for member approval. |
| `preview_create_note_folder` | Notes | `create` | Prepare a private note folder for member approval. |
| `preview_update_note_folder` | Notes | `edit` | Prepare a version-checked folder rename for member approval. |
| `preview_delete_note_folder` | Notes | `edit` | Prepare deletion of an owned note folder for member approval. |
| `preview_add_task_checklist_item` | Tasks | `edit` | Prepare a checklist item on a visible task for member approval. Requires the task’s current version. |
| `preview_update_task_checklist_item` | Tasks | `edit` | Prepare a version-checked checklist item change for member approval. |
| `preview_set_task_checklist_item_completed` | Tasks | `edit` | Prepare checking or unchecking one task checklist item for member approval. |
| `preview_reorder_task_checklist_items` | Tasks | `edit` | Prepare a new checklist order for member approval. The list must contain every current item id exactly once. |
| `preview_delete_task_checklist_item` | Tasks | `edit` | Prepare deletion of one task checklist item for member approval. |
| `preview_copy_previous_meal_plan_week` | Meal Planning | `create` | Prepare copying last week’s planned meals into the week containing the given date for member approval. Existing slots are skipped. |
| `preview_delete_utility_reading` | Utilities | `edit` | Prepare deletion of one authorized meter reading for member approval. |
| `preview_delete_vehicle_odometer_reading` | Vehicles | `edit` | Prepare deletion of one authorized odometer reading for member approval. |
| `preview_delete_vehicle_record` | Vehicles | `edit` | Prepare deletion of a maintenance record, insurance policy or cost entry for member approval. Attached invoice files are removed with a cost. |
| `preview_update_vehicle_cost` | Vehicles | `edit` | Prepare a full replacement of a cost entry’s fields for member approval. Invoice files cannot be changed through MCP. |

## Examples

Read, then change something (JSON-RPC bodies; the client library normally builds these):

```json
{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"list_visible_vehicles","arguments":{}}}
{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"preview_add_task_checklist_item","arguments":{"task_id":42,"expected_version":3,"title":"Pack passports","reminder_lead_minutes":0}}}
{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"complete_approved_mcp_change","arguments":{"preview_id":"<uuid from the preview>","idempotency_key":"<fresh uuid>"}}}
```

The preview result is `{"preview_id", "state": "pending_human_approval", "action", "details", "expires_at"}`. Completion fails until the owner has approved the preview in Metricove.

## Intentionally not exposed

Authentication, member, module and permission administration, token management and approvals; AI assistant conversation and personal context; file uploads and original-file downloads (receipts, meter photos, invoices, attachments, CSV imports); receipt sharing and relinking; external-content features (recipe URL import, AI meal suggestions). See [REST API guide](../api/README.md#intentional-exclusions) for the reasoning.
