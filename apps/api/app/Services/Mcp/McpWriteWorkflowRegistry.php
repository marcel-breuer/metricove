<?php

namespace App\Services\Mcp;

use App\Contracts\ModuleResource;
use App\Enums\ModuleCapability;
use App\Exceptions\ModuleDisabled;
use App\Models\AnalyticsWidget;
use App\Models\FinanceBudget;
use App\Models\FinanceCategory;
use App\Models\FinanceRecurringEntry;
use App\Models\FinancialTransaction;
use App\Models\HouseholdTask;
use App\Models\MealPlanSlot;
use App\Models\PantryItem;
use App\Models\Recipe;
use App\Models\ShoppingItem;
use App\Models\ShoppingList;
use App\Models\TrackerDefinition;
use App\Models\TrackerEntry;
use App\Models\User;
use App\Models\UtilityMeter;
use App\Models\UtilityReading;
use App\Models\Vehicle;
use App\Models\VehicleOdometerReading;
use App\Services\Analytics\AnalyticsService;
use App\Services\Finance\FinanceDecimalValue;
use App\Services\Finance\FinanceService;
use App\Services\MealPlanning\MealPlanningService;
use App\Services\Modules\ModuleAccessService;
use App\Services\Pantry\PantryQuantity;
use App\Services\Pantry\PantryService;
use App\Services\Recipes\RecipeQuantity;
use App\Services\Recipes\RecipeService;
use App\Services\Shopping\ShoppingService;
use App\Services\Tasks\HouseholdTaskService;
use App\Services\Tracking\TrackerEntryService;
use App\Services\Tracking\TrackerSchemaService;
use App\Services\Utilities\DecimalValue;
use App\Services\Utilities\UtilityService;
use App\Services\Vehicles\VehicleDecimalValue;
use App\Services\Vehicles\VehicleService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Explicit catalog of bounded, domain-service-backed MCP writes.
 *
 * @phpstan-type WorkflowDefinition array{module: string, capability: string, title: string, description: string, schema: array<string, mixed>, rules: array<string, mixed>, execute: callable(User, array<string, mixed>): mixed, capabilities?: list<string>}
 */
class McpWriteWorkflowRegistry
{
    /** @var array<string, WorkflowDefinition> */
    private array $workflows;

    public function __construct(
        private readonly Container $container,
        private readonly ModuleAccessService $access,
    ) {
        $this->workflows = [
            'create_vehicle' => [
                'module' => 'vehicles', 'capability' => 'create', 'title' => 'Create a vehicle', 'description' => 'Prepare a private or household vehicle for member approval.',
                'schema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string', 'maxLength' => 120], 'make' => ['type' => 'string', 'maxLength' => 80], 'model' => ['type' => 'string', 'maxLength' => 80], 'model_year' => ['type' => 'integer', 'minimum' => 1886, 'maximum' => 2200], 'fuel_type' => ['type' => 'string', 'enum' => ['petrol', 'diesel', 'hybrid', 'electric', 'lpg', 'other']], 'odometer_unit' => ['type' => 'string', 'enum' => ['km', 'mi']], 'description' => ['type' => 'string', 'maxLength' => 2000], 'visibility' => ['type' => 'string', 'enum' => ['private', 'household']]], 'required' => ['name', 'make', 'model', 'fuel_type', 'odometer_unit', 'visibility'], 'additionalProperties' => false],
                'rules' => ['name' => ['required', 'string', 'max:120'], 'make' => ['required', 'string', 'max:80'], 'model' => ['required', 'string', 'max:80'], 'model_year' => ['nullable', 'integer', 'min:1886', 'max:2200'], 'fuel_type' => ['required', 'in:petrol,diesel,hybrid,electric,lpg,other'], 'odometer_unit' => ['required', 'in:km,mi'], 'description' => ['nullable', 'string', 'max:2000'], 'visibility' => ['required', 'in:private,household']],
                'execute' => fn (User $actor, array $data): array => $this->container->make(VehicleService::class)->create($actor, $data),
            ],
            'update_vehicle' => [
                'module' => 'vehicles', 'capability' => 'manage', 'title' => 'Update a vehicle', 'description' => 'Prepare a vehicle detail change for member approval.',
                'schema' => ['type' => 'object', 'properties' => ['vehicle_id' => ['type' => 'integer', 'minimum' => 1], 'name' => ['type' => 'string', 'maxLength' => 120], 'make' => ['type' => 'string', 'maxLength' => 80], 'model' => ['type' => 'string', 'maxLength' => 80], 'model_year' => ['type' => 'integer', 'minimum' => 1886, 'maximum' => 2200], 'fuel_type' => ['type' => 'string', 'enum' => ['petrol', 'diesel', 'hybrid', 'electric', 'lpg', 'other']], 'odometer_unit' => ['type' => 'string', 'enum' => ['km', 'mi']], 'description' => ['type' => 'string', 'maxLength' => 2000]], 'required' => ['vehicle_id'], 'additionalProperties' => false],
                'rules' => ['vehicle_id' => ['required', 'integer', 'min:1'], 'name' => ['sometimes', 'required', 'string', 'max:120'], 'make' => ['sometimes', 'required', 'string', 'max:80'], 'model' => ['sometimes', 'required', 'string', 'max:80'], 'model_year' => ['sometimes', 'nullable', 'integer', 'min:1886', 'max:2200'], 'fuel_type' => ['sometimes', 'required', 'in:petrol,diesel,hybrid,electric,lpg,other'], 'odometer_unit' => ['sometimes', 'required', 'in:km,mi'], 'description' => ['sometimes', 'nullable', 'string', 'max:2000']],
                'execute' => function (User $actor, array $data): array {
                    $id = (int) $data['vehicle_id'];
                    unset($data['vehicle_id']);

                    return $this->container->make(VehicleService::class)->update($actor, $id, $data);
                },
            ],
            'add_vehicle_maintenance' => [
                'module' => 'vehicles', 'capability' => 'create', 'title' => 'Add vehicle maintenance', 'description' => 'Prepare a maintenance record for a visible vehicle for member approval.',
                'schema' => ['type' => 'object', 'properties' => ['vehicle_id' => ['type' => 'integer', 'minimum' => 1], 'title' => ['type' => 'string', 'maxLength' => 160], 'service_type' => ['type' => 'string', 'enum' => ['service', 'repair', 'inspection', 'tires', 'registration', 'other']], 'occurred_on' => ['type' => 'string', 'format' => 'date'], 'next_due_on' => ['type' => 'string', 'format' => 'date'], 'odometer_value' => ['type' => 'string', 'maxLength' => 24], 'note' => ['type' => 'string', 'maxLength' => 2000]], 'required' => ['vehicle_id', 'title', 'service_type', 'occurred_on'], 'additionalProperties' => false],
                'rules' => ['vehicle_id' => ['required', 'integer', 'min:1'], 'title' => ['required', 'string', 'max:160'], 'service_type' => ['required', 'in:service,repair,inspection,tires,registration,other'], 'occurred_on' => ['required', 'date_format:Y-m-d'], 'next_due_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:occurred_on'], 'odometer_value' => ['nullable', 'string', 'regex:/\\A(?:0|[1-9]\\d{0,16})(?:\\.\\d{1,3})?\\z/'], 'note' => ['nullable', 'string', 'max:2000']],
                'execute' => function (User $actor, array $data): array {
                    $id = (int) $data['vehicle_id'];
                    unset($data['vehicle_id']);

                    return $this->container->make(VehicleService::class)->addMaintenance($actor, $id, $data);
                },
            ],
            'add_vehicle_insurance' => [
                'module' => 'vehicles', 'capability' => 'create', 'title' => 'Add vehicle insurance', 'description' => 'Prepare an insurance policy for a visible vehicle for member approval.',
                'schema' => ['type' => 'object', 'properties' => ['vehicle_id' => ['type' => 'integer', 'minimum' => 1], 'provider' => ['type' => 'string', 'maxLength' => 120], 'coverage_type' => ['type' => 'string', 'enum' => ['liability', 'partial', 'comprehensive', 'other']], 'valid_from' => ['type' => 'string', 'format' => 'date'], 'valid_until' => ['type' => 'string', 'format' => 'date'], 'note' => ['type' => 'string', 'maxLength' => 2000]], 'required' => ['vehicle_id', 'provider', 'coverage_type', 'valid_from', 'valid_until'], 'additionalProperties' => false],
                'rules' => ['vehicle_id' => ['required', 'integer', 'min:1'], 'provider' => ['required', 'string', 'max:120'], 'coverage_type' => ['required', 'in:liability,partial,comprehensive,other'], 'valid_from' => ['required', 'date_format:Y-m-d'], 'valid_until' => ['required', 'date_format:Y-m-d', 'after_or_equal:valid_from'], 'note' => ['nullable', 'string', 'max:2000']],
                'execute' => function (User $actor, array $data): array {
                    $id = (int) $data['vehicle_id'];
                    unset($data['vehicle_id']);

                    return $this->container->make(VehicleService::class)->addInsurance($actor, $id, $data);
                },
            ],
            'add_vehicle_cost' => [
                'module' => 'vehicles', 'capability' => 'create', 'title' => 'Add a vehicle cost', 'description' => 'Prepare a cost entry for a visible vehicle for member approval.',
                'schema' => ['type' => 'object', 'properties' => ['vehicle_id' => ['type' => 'integer', 'minimum' => 1], 'category' => ['type' => 'string', 'enum' => ['fuel', 'maintenance', 'insurance', 'tax', 'registration', 'finance', 'other']], 'amount' => ['type' => 'string', 'maxLength' => 24], 'currency' => ['type' => 'string', 'pattern' => '^[A-Z]{3}$'], 'period_start' => ['type' => 'string', 'format' => 'date'], 'period_end' => ['type' => 'string', 'format' => 'date'], 'period' => ['type' => 'string', 'enum' => ['once', 'monthly', 'quarterly', 'yearly']], 'description' => ['type' => 'string', 'maxLength' => 500]], 'required' => ['vehicle_id', 'category', 'amount', 'currency', 'period_start', 'period'], 'additionalProperties' => false],
                'rules' => ['vehicle_id' => ['required', 'integer', 'min:1'], 'category' => ['required', 'in:fuel,maintenance,insurance,tax,registration,finance,other'], 'amount' => ['required', 'string', 'regex:/\\A(?:0|[1-9]\\d{0,15})(?:\\.\\d{1,2})?\\z/'], 'currency' => ['required', 'string', 'regex:/\\A[A-Z]{3}\\z/'], 'period_start' => ['required', 'date_format:Y-m-d'], 'period_end' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:period_start'], 'period' => ['required', 'in:once,monthly,quarterly,yearly'], 'description' => ['nullable', 'string', 'max:500']],
                'execute' => function (User $actor, array $data): array {
                    $id = (int) $data['vehicle_id'];
                    unset($data['vehicle_id']);

                    return $this->container->make(VehicleService::class)->addCost($actor, $id, $data);
                },
            ],
            'create_utility_meter' => [
                'module' => 'utilities', 'capability' => 'create', 'title' => 'Create a utility meter', 'description' => 'Prepare a private or shared utility meter for member approval.',
                'schema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string', 'maxLength' => 120], 'meter_type' => ['type' => 'string', 'enum' => ['electricity', 'gas', 'water']], 'visibility' => ['type' => 'string', 'enum' => ['private', 'household']], 'description' => ['type' => 'string', 'maxLength' => 2000], 'alert_threshold' => ['type' => 'string', 'maxLength' => 24]], 'required' => ['name', 'meter_type', 'visibility'], 'additionalProperties' => false],
                'rules' => ['name' => ['required', 'string', 'max:120'], 'meter_type' => ['required', 'in:electricity,gas,water'], 'visibility' => ['required', 'in:private,household'], 'description' => ['nullable', 'string', 'max:2000'], 'alert_threshold' => ['nullable', 'string', 'regex:/\\A(?:0|[1-9]\\d{0,13})(?:\\.\\d{1,6})?\\z/']],
                'execute' => fn (User $actor, array $data): array => $this->container->make(UtilityService::class)->createMeter($actor, $data),
            ],
            'update_utility_meter' => [
                'module' => 'utilities', 'capability' => 'manage', 'title' => 'Update a utility meter', 'description' => 'Prepare a utility meter detail update for member approval.',
                'schema' => ['type' => 'object', 'properties' => ['meter_id' => ['type' => 'integer', 'minimum' => 1], 'name' => ['type' => 'string', 'maxLength' => 120], 'alert_threshold' => ['type' => 'string', 'maxLength' => 24]], 'required' => ['meter_id'], 'additionalProperties' => false],
                'rules' => ['meter_id' => ['required', 'integer', 'min:1'], 'name' => ['sometimes', 'required', 'string', 'max:120'], 'alert_threshold' => ['sometimes', 'nullable', 'string', 'regex:/\\A(?:0|[1-9]\\d{0,13})(?:\\.\\d{1,6})?\\z/']],
                'execute' => function (User $actor, array $data): array {
                    $id = (int) $data['meter_id'];
                    unset($data['meter_id']);

                    return $this->container->make(UtilityService::class)->updateMeter($actor, $id, $data);
                },
            ],
            'create_recipe' => [
                'module' => 'recipes', 'capability' => 'create', 'title' => 'Create a recipe',
                'description' => 'Prepare a recipe for member approval. The recipe is saved only after approval in Metricove.',
                'schema' => ['type' => 'object', 'properties' => [
                    'title' => ['type' => 'string', 'maxLength' => 180], 'category' => ['type' => 'string', 'maxLength' => 80],
                    'instructions' => ['type' => 'string', 'maxLength' => 20000], 'servings' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000],
                    'preparation_minutes' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1440], 'visibility' => ['type' => 'string', 'enum' => ['private', 'household']],
                    'ingredients' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 100, 'items' => ['type' => 'object', 'properties' => [
                        'name' => ['type' => 'string', 'maxLength' => 120], 'quantity' => ['type' => 'string', 'maxLength' => 32],
                        'unit' => ['type' => 'string', 'maxLength' => 40], 'note' => ['type' => 'string', 'maxLength' => 160],
                    ], 'required' => ['name', 'quantity', 'unit'], 'additionalProperties' => false]],
                ], 'required' => ['title', 'instructions', 'servings', 'visibility', 'ingredients'], 'additionalProperties' => false],
                'rules' => ['title' => ['required', 'string', 'max:180'], 'category' => ['nullable', 'string', 'max:80'], 'instructions' => ['required', 'string', 'max:20000'], 'servings' => ['required', 'integer', 'between:1,1000'], 'preparation_minutes' => ['nullable', 'integer', 'between:1,1440'], 'visibility' => ['required', 'in:private,household'], 'ingredients' => ['required', 'array', 'min:1', 'max:100'], 'ingredients.*' => ['required', 'array:name,quantity,unit,note'], 'ingredients.*.name' => ['required', 'string', 'max:120'], 'ingredients.*.quantity' => ['required', 'string', 'max:32'], 'ingredients.*.unit' => ['required', 'string', 'max:40'], 'ingredients.*.note' => ['nullable', 'string', 'max:160']],
                'execute' => fn (User $actor, array $data): array => $this->container->make(RecipeService::class)->create($actor, $data),
            ],
            'favorite_recipe' => [
                'module' => 'recipes', 'capability' => 'edit', 'title' => 'Favorite a recipe', 'description' => 'Prepare a personal recipe favorite for member approval.',
                'schema' => ['type' => 'object', 'properties' => ['recipe_id' => ['type' => 'integer', 'minimum' => 1]], 'required' => ['recipe_id'], 'additionalProperties' => false],
                'rules' => ['recipe_id' => ['required', 'integer', 'min:1']],
                'execute' => function (User $actor, array $data): array {
                    return $this->container->make(RecipeService::class)->favorite($actor, (int) $data['recipe_id']);
                },
            ],
            'unfavorite_recipe' => [
                'module' => 'recipes', 'capability' => 'edit', 'title' => 'Remove a recipe favorite', 'description' => 'Prepare removal of a personal recipe favorite for member approval.',
                'schema' => ['type' => 'object', 'properties' => ['recipe_id' => ['type' => 'integer', 'minimum' => 1]], 'required' => ['recipe_id'], 'additionalProperties' => false],
                'rules' => ['recipe_id' => ['required', 'integer', 'min:1']],
                'execute' => function (User $actor, array $data): array {
                    $this->container->make(RecipeService::class)->unfavorite($actor, (int) $data['recipe_id']);

                    return ['status' => 'removed'];
                },
            ],
            'create_meal_plan_slot' => [
                'module' => 'meal_planning', 'capability' => 'create', 'title' => 'Plan a meal',
                'description' => 'Prepare a free-text dish or recipe-linked meal for member approval.',
                'schema' => ['type' => 'object', 'properties' => [
                    'planned_for' => ['type' => 'string', 'format' => 'date'], 'meal_type' => ['type' => 'string', 'enum' => ['breakfast', 'lunch', 'dinner']],
                    'dish_type' => ['type' => 'string', 'enum' => ['free_text', 'recipe']], 'dish_name' => ['type' => 'string', 'maxLength' => 180],
                    'recipe_id' => ['type' => 'integer', 'minimum' => 1], 'servings' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000],
                    'notes' => ['type' => 'string', 'maxLength' => 2000], 'visibility' => ['type' => 'string', 'enum' => ['private', 'household']],
                ], 'required' => ['planned_for', 'meal_type', 'dish_type', 'servings', 'visibility'], 'additionalProperties' => false],
                'rules' => ['planned_for' => ['required', 'date_format:Y-m-d'], 'meal_type' => ['required', 'in:breakfast,lunch,dinner'], 'dish_type' => ['required', 'in:free_text,recipe'], 'dish_name' => ['required_if:dish_type,free_text', 'nullable', 'string', 'max:180'], 'recipe_id' => ['required_if:dish_type,recipe', 'nullable', 'integer', 'min:1'], 'servings' => ['required', 'integer', 'between:1,1000'], 'notes' => ['nullable', 'string', 'max:2000'], 'visibility' => ['required', 'in:private,household']],
                'execute' => fn (User $actor, array $data): array => $this->container->make(MealPlanningService::class)->create($actor, $data),
            ],
            'update_meal_plan_slot' => [
                'module' => 'meal_planning', 'capability' => 'edit', 'title' => 'Update a planned meal', 'description' => 'Prepare changes to a meal plan slot for member approval.',
                'schema' => ['type' => 'object', 'properties' => ['slot_id' => ['type' => 'integer', 'minimum' => 1], 'planned_for' => ['type' => 'string', 'format' => 'date'], 'meal_type' => ['type' => 'string', 'enum' => ['breakfast', 'lunch', 'dinner']], 'dish_type' => ['type' => 'string', 'enum' => ['free_text', 'recipe']], 'dish_name' => ['type' => 'string', 'maxLength' => 180], 'recipe_id' => ['type' => 'integer', 'minimum' => 1], 'servings' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000], 'notes' => ['type' => 'string', 'maxLength' => 2000]], 'required' => ['slot_id'], 'additionalProperties' => false],
                'rules' => ['slot_id' => ['required', 'integer', 'min:1'], 'planned_for' => ['sometimes', 'required', 'date_format:Y-m-d'], 'meal_type' => ['sometimes', 'required', 'in:breakfast,lunch,dinner'], 'dish_type' => ['sometimes', 'required', 'in:free_text,recipe'], 'dish_name' => ['sometimes', 'required_if:dish_type,free_text', 'nullable', 'string', 'max:180'], 'recipe_id' => ['sometimes', 'required_if:dish_type,recipe', 'nullable', 'integer', 'min:1'], 'servings' => ['sometimes', 'required', 'integer', 'between:1,1000'], 'notes' => ['sometimes', 'nullable', 'string', 'max:2000']],
                'execute' => function (User $actor, array $data): array {
                    $id = (int) $data['slot_id'];
                    unset($data['slot_id']);

                    return $this->container->make(MealPlanningService::class)->update($actor, $id, $data);
                },
            ],
            'create_pantry_item' => [
                'module' => 'pantry', 'capability' => 'create', 'title' => 'Add pantry stock',
                'description' => 'Prepare a pantry item for member approval.',
                'schema' => ['type' => 'object', 'properties' => [
                    'name' => ['type' => 'string', 'maxLength' => 180], 'quantity' => ['type' => 'string', 'pattern' => '^(0|[1-9][0-9]{0,10})(\\.[0-9]{1,3})?$'],
                    'unit' => ['type' => 'string', 'maxLength' => 32], 'location' => ['type' => 'string', 'maxLength' => 120],
                    'best_before_on' => ['type' => 'string', 'format' => 'date'], 'visibility' => ['type' => 'string', 'enum' => ['private', 'household']],
                ], 'required' => ['name', 'quantity', 'unit', 'visibility'], 'additionalProperties' => false],
                'rules' => ['name' => ['required', 'string', 'max:180'], 'quantity' => ['required', 'string', 'regex:/\\A(?:0|[1-9]\\d{0,10})(?:\\.\\d{1,3})?\\z/'], 'unit' => ['required', 'string', 'max:32'], 'location' => ['nullable', 'string', 'max:120'], 'best_before_on' => ['nullable', 'date_format:Y-m-d'], 'visibility' => ['required', 'in:private,household']],
                'execute' => fn (User $actor, array $data): array => $this->container->make(PantryService::class)->create($actor, $data),
            ],
            'update_pantry_item' => [
                'module' => 'pantry', 'capability' => 'edit', 'title' => 'Update a pantry item', 'description' => 'Prepare a version-checked pantry detail update for approval.',
                'schema' => ['type' => 'object', 'properties' => ['item_id' => ['type' => 'integer', 'minimum' => 1], 'expected_version' => ['type' => 'integer', 'minimum' => 1], 'name' => ['type' => 'string', 'maxLength' => 180], 'location' => ['type' => 'string', 'maxLength' => 120], 'best_before_on' => ['type' => 'string', 'format' => 'date']], 'required' => ['item_id', 'expected_version'], 'additionalProperties' => false],
                'rules' => ['item_id' => ['required', 'integer', 'min:1'], 'expected_version' => ['required', 'integer', 'min:1'], 'name' => ['sometimes', 'required', 'string', 'max:180'], 'location' => ['sometimes', 'nullable', 'string', 'max:120'], 'best_before_on' => ['sometimes', 'nullable', 'date_format:Y-m-d']],
                'execute' => function (User $actor, array $data): array {
                    $id = (int) $data['item_id'];
                    unset($data['item_id']);

                    return $this->container->make(PantryService::class)->update($actor, $id, $data);
                },
            ],
            'create_shopping_list' => [
                'module' => 'shopping', 'capability' => 'create', 'title' => 'Create a shopping list',
                'description' => 'Prepare a shopping list for member approval.',
                'schema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string', 'maxLength' => 160], 'visibility' => ['type' => 'string', 'enum' => ['private', 'household']]], 'required' => ['name', 'visibility'], 'additionalProperties' => false],
                'rules' => ['name' => ['required', 'string', 'max:160'], 'visibility' => ['required', 'in:private,household']],
                'execute' => fn (User $actor, array $data): array => $this->container->make(ShoppingService::class)->createList($actor, $data),
            ],
            'update_shopping_list' => [
                'module' => 'shopping', 'capability' => 'edit', 'title' => 'Rename a shopping list', 'description' => 'Prepare a version-checked shopping list rename for member approval.',
                'schema' => ['type' => 'object', 'properties' => ['list_id' => ['type' => 'integer', 'minimum' => 1], 'expected_version' => ['type' => 'integer', 'minimum' => 1], 'name' => ['type' => 'string', 'maxLength' => 160]], 'required' => ['list_id', 'expected_version', 'name'], 'additionalProperties' => false],
                'rules' => ['list_id' => ['required', 'integer', 'min:1'], 'expected_version' => ['required', 'integer', 'min:1'], 'name' => ['required', 'string', 'max:160']],
                'execute' => function (User $actor, array $data): array {
                    $id = (int) $data['list_id'];
                    unset($data['list_id']);

                    return $this->container->make(ShoppingService::class)->updateList($actor, $id, $data);
                },
            ],
            'create_financial_transaction' => [
                'module' => 'finance', 'capability' => 'create', 'title' => 'Create a financial transaction',
                'description' => 'Prepare a private or household transaction for member approval.',
                'schema' => ['type' => 'object', 'properties' => [
                    'finance_category_id' => ['type' => 'integer', 'minimum' => 1], 'type' => ['type' => 'string', 'enum' => ['income', 'expense']],
                    'description' => ['type' => 'string', 'maxLength' => 200], 'payee' => ['type' => 'string', 'maxLength' => 200],
                    'amount' => ['type' => 'string', 'maxLength' => 24], 'currency' => ['type' => 'string', 'pattern' => '^[A-Z]{3}$'],
                    'occurred_on' => ['type' => 'string', 'format' => 'date'], 'visibility' => ['type' => 'string', 'enum' => ['private', 'household']],
                ], 'required' => ['finance_category_id', 'type', 'description', 'amount', 'currency', 'occurred_on', 'visibility'], 'additionalProperties' => false],
                'rules' => ['finance_category_id' => ['required', 'integer', 'min:1'], 'type' => ['required', 'in:income,expense'], 'description' => ['required', 'string', 'max:200'], 'payee' => ['nullable', 'string', 'max:200'], 'amount' => ['required', 'string', 'regex:/\\A(?:0|[1-9]\\d{0,15})(?:\\.\\d{1,2})?\\z/'], 'currency' => ['required', 'string', 'regex:/\\A[A-Z]{3}\\z/'], 'occurred_on' => ['required', 'date_format:Y-m-d'], 'visibility' => ['required', 'in:private,household']],
                'execute' => fn (User $actor, array $data): array => $this->container->make(FinanceService::class)->createTransaction($actor, $data),
            ],
            'create_finance_category' => [
                'module' => 'finance', 'capability' => 'create', 'title' => 'Create a finance category',
                'description' => 'Prepare a private or household category for member approval.',
                'schema' => ['type' => 'object', 'properties' => ['type' => ['type' => 'string', 'enum' => ['income', 'expense']], 'name' => ['type' => 'string', 'maxLength' => 80], 'visibility' => ['type' => 'string', 'enum' => ['private', 'household']]], 'required' => ['type', 'name', 'visibility'], 'additionalProperties' => false],
                'rules' => ['type' => ['required', 'in:income,expense'], 'name' => ['required', 'string', 'max:80'], 'visibility' => ['required', 'in:private,household']],
                'execute' => fn (User $actor, array $data): array => $this->container->make(FinanceService::class)->createCategory($actor, $data),
            ],
            'update_finance_category' => [
                'module' => 'finance', 'capability' => 'edit', 'title' => 'Rename a finance category', 'description' => 'Prepare a category rename for member approval.',
                'schema' => ['type' => 'object', 'properties' => ['category_id' => ['type' => 'integer', 'minimum' => 1], 'name' => ['type' => 'string', 'maxLength' => 80]], 'required' => ['category_id', 'name'], 'additionalProperties' => false],
                'rules' => ['category_id' => ['required', 'integer', 'min:1'], 'name' => ['required', 'string', 'max:80']],
                'execute' => function (User $actor, array $data): array {
                    $id = (int) $data['category_id'];
                    unset($data['category_id']);

                    return $this->container->make(FinanceService::class)->updateCategory($actor, $id, $data);
                },
            ],
            'delete_finance_category' => [
                'module' => 'finance', 'capability' => 'edit', 'title' => 'Delete a finance category', 'description' => 'Prepare deletion of an unused category for member approval.',
                'schema' => ['type' => 'object', 'properties' => ['category_id' => ['type' => 'integer', 'minimum' => 1]], 'required' => ['category_id'], 'additionalProperties' => false],
                'rules' => ['category_id' => ['required', 'integer', 'min:1']],
                'execute' => function (User $actor, array $data): array {
                    $this->container->make(FinanceService::class)->deleteCategory($actor, (int) $data['category_id']);

                    return ['status' => 'deleted'];
                },
            ],
            'create_finance_budget' => [
                'module' => 'finance', 'capability' => 'create', 'title' => 'Create a finance budget', 'description' => 'Prepare a monthly category budget for member approval.',
                'schema' => ['type' => 'object', 'properties' => ['finance_category_id' => ['type' => 'integer', 'minimum' => 1], 'amount' => ['type' => 'string', 'maxLength' => 24], 'currency' => ['type' => 'string', 'pattern' => '^[A-Z]{3}$'], 'period_start' => ['type' => 'string', 'format' => 'date'], 'visibility' => ['type' => 'string', 'enum' => ['private', 'household']]], 'required' => ['finance_category_id', 'amount', 'currency', 'period_start', 'visibility'], 'additionalProperties' => false],
                'rules' => ['finance_category_id' => ['required', 'integer', 'min:1'], 'amount' => ['required', 'string', 'regex:/\\A(?:0|[1-9]\\d{0,15})(?:\\.\\d{1,2})?\\z/'], 'currency' => ['required', 'string', 'regex:/\\A[A-Z]{3}\\z/'], 'period_start' => ['required', 'date_format:Y-m-d'], 'visibility' => ['required', 'in:private,household']],
                'execute' => fn (User $actor, array $data): array => $this->container->make(FinanceService::class)->createBudget($actor, $data),
            ],
            'update_finance_budget' => [
                'module' => 'finance', 'capability' => 'edit', 'title' => 'Update a finance budget', 'description' => 'Prepare a budget change for member approval.',
                'schema' => ['type' => 'object', 'properties' => ['budget_id' => ['type' => 'integer', 'minimum' => 1], 'finance_category_id' => ['type' => 'integer', 'minimum' => 1], 'amount' => ['type' => 'string', 'maxLength' => 24], 'currency' => ['type' => 'string', 'pattern' => '^[A-Z]{3}$'], 'period_start' => ['type' => 'string', 'format' => 'date']], 'required' => ['budget_id'], 'additionalProperties' => false],
                'rules' => ['budget_id' => ['required', 'integer', 'min:1'], 'finance_category_id' => ['sometimes', 'required', 'integer', 'min:1'], 'amount' => ['sometimes', 'required', 'string', 'regex:/\\A(?:0|[1-9]\\d{0,15})(?:\\.\\d{1,2})?\\z/'], 'currency' => ['sometimes', 'required', 'string', 'regex:/\\A[A-Z]{3}\\z/'], 'period_start' => ['sometimes', 'required', 'date_format:Y-m-d']],
                'execute' => function (User $actor, array $data): array {
                    $id = (int) $data['budget_id'];
                    unset($data['budget_id']);

                    return $this->container->make(FinanceService::class)->updateBudget($actor, $id, $data);
                },
            ],
            'delete_finance_budget' => [
                'module' => 'finance', 'capability' => 'edit', 'title' => 'Delete a finance budget', 'description' => 'Prepare budget deletion for member approval.',
                'schema' => ['type' => 'object', 'properties' => ['budget_id' => ['type' => 'integer', 'minimum' => 1]], 'required' => ['budget_id'], 'additionalProperties' => false],
                'rules' => ['budget_id' => ['required', 'integer', 'min:1']],
                'execute' => function (User $actor, array $data): array {
                    $this->container->make(FinanceService::class)->deleteBudget($actor, (int) $data['budget_id']);

                    return ['status' => 'deleted'];
                },
            ],
            'create_finance_recurring_entry' => [
                'module' => 'finance', 'capability' => 'create', 'title' => 'Create a recurring finance entry', 'description' => 'Prepare a private or household recurring entry for member approval.',
                'schema' => ['type' => 'object', 'properties' => ['finance_category_id' => ['type' => 'integer', 'minimum' => 1], 'type' => ['type' => 'string', 'enum' => ['income', 'expense']], 'description' => ['type' => 'string', 'maxLength' => 200], 'amount' => ['type' => 'string', 'maxLength' => 24], 'currency' => ['type' => 'string', 'pattern' => '^[A-Z]{3}$'], 'frequency' => ['type' => 'string', 'enum' => ['weekly', 'monthly', 'quarterly', 'yearly']], 'starts_on' => ['type' => 'string', 'format' => 'date'], 'next_due_on' => ['type' => 'string', 'format' => 'date'], 'ends_on' => ['type' => 'string', 'format' => 'date'], 'visibility' => ['type' => 'string', 'enum' => ['private', 'household']]], 'required' => ['finance_category_id', 'type', 'description', 'amount', 'currency', 'frequency', 'starts_on', 'next_due_on', 'visibility'], 'additionalProperties' => false],
                'rules' => ['finance_category_id' => ['required', 'integer', 'min:1'], 'type' => ['required', 'in:income,expense'], 'description' => ['required', 'string', 'max:200'], 'amount' => ['required', 'string', 'regex:/\\A(?:0|[1-9]\\d{0,15})(?:\\.\\d{1,2})?\\z/'], 'currency' => ['required', 'string', 'regex:/\\A[A-Z]{3}\\z/'], 'frequency' => ['required', 'in:weekly,monthly,quarterly,yearly'], 'starts_on' => ['required', 'date_format:Y-m-d'], 'next_due_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'], 'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'], 'visibility' => ['required', 'in:private,household']],
                'execute' => fn (User $actor, array $data): array => $this->container->make(FinanceService::class)->createRecurringEntry($actor, $data),
            ],
            'update_finance_recurring_entry' => [
                'module' => 'finance', 'capability' => 'edit', 'title' => 'Update a recurring finance entry', 'description' => 'Prepare a recurring entry update for member approval.',
                'schema' => ['type' => 'object', 'properties' => ['entry_id' => ['type' => 'integer', 'minimum' => 1], 'finance_category_id' => ['type' => 'integer', 'minimum' => 1], 'type' => ['type' => 'string', 'enum' => ['income', 'expense']], 'description' => ['type' => 'string', 'maxLength' => 200], 'amount' => ['type' => 'string', 'maxLength' => 24], 'currency' => ['type' => 'string', 'pattern' => '^[A-Z]{3}$'], 'frequency' => ['type' => 'string', 'enum' => ['weekly', 'monthly', 'quarterly', 'yearly']], 'starts_on' => ['type' => 'string', 'format' => 'date'], 'next_due_on' => ['type' => 'string', 'format' => 'date'], 'ends_on' => ['type' => 'string', 'format' => 'date'], 'is_active' => ['type' => 'boolean']], 'required' => ['entry_id'], 'additionalProperties' => false],
                'rules' => ['entry_id' => ['required', 'integer', 'min:1'], 'finance_category_id' => ['sometimes', 'required', 'integer', 'min:1'], 'type' => ['sometimes', 'required', 'in:income,expense'], 'description' => ['sometimes', 'required', 'string', 'max:200'], 'amount' => ['sometimes', 'required', 'string', 'regex:/\\A(?:0|[1-9]\\d{0,15})(?:\\.\\d{1,2})?\\z/'], 'currency' => ['sometimes', 'required', 'string', 'regex:/\\A[A-Z]{3}\\z/'], 'frequency' => ['sometimes', 'required', 'in:weekly,monthly,quarterly,yearly'], 'starts_on' => ['sometimes', 'required', 'date_format:Y-m-d'], 'next_due_on' => ['sometimes', 'required', 'date_format:Y-m-d'], 'ends_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'is_active' => ['sometimes', 'boolean']],
                'execute' => function (User $actor, array $data): array {
                    $id = (int) $data['entry_id'];
                    unset($data['entry_id']);

                    return $this->container->make(FinanceService::class)->updateRecurringEntry($actor, $id, $data);
                },
            ],
            'delete_finance_recurring_entry' => [
                'module' => 'finance', 'capability' => 'edit', 'title' => 'Delete a recurring finance entry', 'description' => 'Prepare recurring entry deletion for member approval.',
                'schema' => ['type' => 'object', 'properties' => ['entry_id' => ['type' => 'integer', 'minimum' => 1]], 'required' => ['entry_id'], 'additionalProperties' => false],
                'rules' => ['entry_id' => ['required', 'integer', 'min:1']],
                'execute' => function (User $actor, array $data): array {
                    $this->container->make(FinanceService::class)->deleteRecurringEntry($actor, (int) $data['entry_id']);

                    return ['status' => 'deleted'];
                },
            ],
            'record_finance_recurring_occurrence' => [
                'module' => 'finance', 'capability' => 'create', 'title' => 'Record a recurring finance occurrence', 'description' => 'Prepare the current due occurrence as a transaction for member approval.',
                'schema' => ['type' => 'object', 'properties' => ['entry_id' => ['type' => 'integer', 'minimum' => 1], 'occurrence_on' => ['type' => 'string', 'format' => 'date'], 'occurred_on' => ['type' => 'string', 'format' => 'date']], 'required' => ['entry_id', 'occurrence_on'], 'additionalProperties' => false],
                'rules' => ['entry_id' => ['required', 'integer', 'min:1'], 'occurrence_on' => ['required', 'date_format:Y-m-d'], 'occurred_on' => ['nullable', 'date_format:Y-m-d']],
                'execute' => function (User $actor, array $data): array {
                    return $this->container->make(FinanceService::class)->recordRecurringOccurrence($actor, (int) $data['entry_id'], array_diff_key($data, ['entry_id' => true]));
                },
            ],
            'update_financial_transaction' => [
                'module' => 'finance', 'capability' => 'edit', 'title' => 'Update a financial transaction', 'description' => 'Prepare a transaction update for member approval.',
                'schema' => ['type' => 'object', 'properties' => ['transaction_id' => ['type' => 'integer', 'minimum' => 1], 'finance_category_id' => ['type' => 'integer', 'minimum' => 1], 'type' => ['type' => 'string', 'enum' => ['income', 'expense']], 'description' => ['type' => 'string', 'maxLength' => 200], 'payee' => ['type' => 'string', 'maxLength' => 200], 'amount' => ['type' => 'string', 'maxLength' => 24], 'currency' => ['type' => 'string', 'pattern' => '^[A-Z]{3}$'], 'occurred_on' => ['type' => 'string', 'format' => 'date']], 'required' => ['transaction_id'], 'additionalProperties' => false],
                'rules' => ['transaction_id' => ['required', 'integer', 'min:1'], 'finance_category_id' => ['sometimes', 'required', 'integer', 'min:1'], 'type' => ['sometimes', 'required', 'in:income,expense'], 'description' => ['sometimes', 'required', 'string', 'max:200'], 'payee' => ['sometimes', 'nullable', 'string', 'max:200'], 'amount' => ['sometimes', 'required', 'string', 'regex:/\\A(?:0|[1-9]\\d{0,15})(?:\\.\\d{1,2})?\\z/'], 'currency' => ['sometimes', 'required', 'string', 'regex:/\\A[A-Z]{3}\\z/'], 'occurred_on' => ['sometimes', 'required', 'date_format:Y-m-d']],
                'execute' => function (User $actor, array $data): array {
                    $id = (int) $data['transaction_id'];
                    unset($data['transaction_id']);

                    return $this->container->make(FinanceService::class)->updateTransaction($actor, $id, $data);
                },
            ],
            'add_tracker_entry' => [
                'module' => 'tracking', 'capability' => 'create', 'title' => 'Add a tracker entry',
                'description' => 'Prepare a typed tracker entry for member approval.',
                'schema' => ['type' => 'object', 'properties' => ['tracker_id' => ['type' => 'integer', 'minimum' => 1], 'schema_version' => ['type' => 'integer', 'minimum' => 1], 'recorded_at' => ['type' => 'string', 'format' => 'date-time'], 'note' => ['type' => 'string', 'maxLength' => 1000], 'values' => ['type' => 'object', 'additionalProperties' => ['type' => ['string', 'number', 'integer', 'boolean', 'null']]]], 'required' => ['tracker_id', 'schema_version', 'recorded_at', 'values'], 'additionalProperties' => false],
                'rules' => ['tracker_id' => ['required', 'integer', 'min:1'], 'schema_version' => ['required', 'integer', 'min:1'], 'recorded_at' => ['required', 'date'], 'note' => ['nullable', 'string', 'max:1000'], 'values' => ['required', 'array', 'max:50']],
                'execute' => function (User $actor, array $data): array {
                    $trackerId = (int) $data['tracker_id'];
                    unset($data['tracker_id']);

                    return $this->container->make(TrackerEntryService::class)->create($actor, $trackerId, $data);
                },
            ],
            'create_tracker' => [
                'module' => 'tracking', 'capability' => 'create', 'title' => 'Create a tracker', 'description' => 'Prepare a private or household typed tracker schema for member approval.',
                'schema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string', 'maxLength' => 120], 'description' => ['type' => 'string', 'maxLength' => 500], 'visibility' => ['type' => 'string', 'enum' => ['private', 'household']], 'fields' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 50, 'items' => ['type' => 'object', 'properties' => ['label' => ['type' => 'string', 'maxLength' => 120], 'type' => ['type' => 'string', 'enum' => ['number', 'text', 'date', 'boolean']], 'unit' => ['type' => 'string', 'maxLength' => 32], 'is_required' => ['type' => 'boolean'], 'minimum' => ['type' => ['number', 'string', 'null']], 'maximum' => ['type' => ['number', 'string', 'null']]], 'required' => ['label', 'type', 'is_required'], 'additionalProperties' => false]]], 'required' => ['name', 'visibility', 'fields'], 'additionalProperties' => false],
                'rules' => ['name' => ['required', 'string', 'max:120'], 'description' => ['nullable', 'string', 'max:500'], 'visibility' => ['required', 'in:private,household'], 'fields' => ['required', 'array', 'min:1', 'max:50'], 'fields.*' => ['required', 'array:label,type,unit,is_required,minimum,maximum'], 'fields.*.label' => ['required', 'string', 'max:120'], 'fields.*.type' => ['required', 'in:number,text,date,boolean'], 'fields.*.unit' => ['nullable', 'string', 'max:32'], 'fields.*.is_required' => ['required', 'boolean'], 'fields.*.minimum' => ['nullable'], 'fields.*.maximum' => ['nullable']],
                'execute' => fn (User $actor, array $data): array => $this->container->make(TrackerSchemaService::class)->create($actor, $data),
            ],
            'update_tracker_schema' => [
                'module' => 'tracking', 'capability' => 'manage', 'title' => 'Update a tracker schema', 'description' => 'Prepare a version-checked schema change for member approval.',
                'schema' => ['type' => 'object', 'properties' => ['tracker_id' => ['type' => 'integer', 'minimum' => 1], 'schema_version' => ['type' => 'integer', 'minimum' => 1], 'name' => ['type' => 'string', 'maxLength' => 120], 'description' => ['type' => 'string', 'maxLength' => 500], 'fields' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 50, 'items' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer', 'minimum' => 1], 'label' => ['type' => 'string', 'maxLength' => 120], 'type' => ['type' => 'string', 'enum' => ['number', 'text', 'date', 'boolean']], 'unit' => ['type' => 'string', 'maxLength' => 32], 'is_required' => ['type' => 'boolean'], 'minimum' => ['type' => ['number', 'string', 'null']], 'maximum' => ['type' => ['number', 'string', 'null']]], 'required' => ['label', 'type', 'is_required'], 'additionalProperties' => false]]], 'required' => ['tracker_id', 'schema_version', 'name', 'fields'], 'additionalProperties' => false],
                'rules' => ['tracker_id' => ['required', 'integer', 'min:1'], 'schema_version' => ['required', 'integer', 'min:1'], 'name' => ['required', 'string', 'max:120'], 'description' => ['nullable', 'string', 'max:500'], 'fields' => ['required', 'array', 'min:1', 'max:50'], 'fields.*' => ['required', 'array:id,label,type,unit,is_required,minimum,maximum'], 'fields.*.id' => ['sometimes', 'integer', 'min:1'], 'fields.*.label' => ['required', 'string', 'max:120'], 'fields.*.type' => ['required', 'in:number,text,date,boolean'], 'fields.*.unit' => ['nullable', 'string', 'max:32'], 'fields.*.is_required' => ['required', 'boolean'], 'fields.*.minimum' => ['nullable'], 'fields.*.maximum' => ['nullable']],
                'execute' => function (User $actor, array $data): array {
                    $id = (int) $data['tracker_id'];
                    unset($data['tracker_id']);

                    return $this->container->make(TrackerSchemaService::class)->update($actor, $id, $data);
                },
            ],
            'delete_tracker' => [
                'module' => 'tracking', 'capability' => 'manage', 'title' => 'Delete a tracker', 'description' => 'Prepare deletion of a tracker and its entry history for member approval.',
                'schema' => ['type' => 'object', 'properties' => ['tracker_id' => ['type' => 'integer', 'minimum' => 1]], 'required' => ['tracker_id'], 'additionalProperties' => false],
                'rules' => ['tracker_id' => ['required', 'integer', 'min:1']],
                'execute' => function (User $actor, array $data): array {
                    $this->container->make(TrackerSchemaService::class)->delete($actor, (int) $data['tracker_id']);

                    return ['status' => 'deleted'];
                },
            ],
            'update_tracker_entry' => [
                'module' => 'tracking', 'capability' => 'edit', 'title' => 'Update a tracker entry', 'description' => 'Prepare a typed tracker entry update for member approval.',
                'schema' => ['type' => 'object', 'properties' => ['entry_id' => ['type' => 'integer', 'minimum' => 1], 'schema_version' => ['type' => 'integer', 'minimum' => 1], 'recorded_at' => ['type' => 'string', 'format' => 'date-time'], 'note' => ['type' => 'string', 'maxLength' => 1000], 'values' => ['type' => 'object', 'additionalProperties' => ['type' => ['string', 'number', 'integer', 'boolean', 'null']]]], 'required' => ['entry_id', 'schema_version', 'recorded_at', 'values'], 'additionalProperties' => false],
                'rules' => ['entry_id' => ['required', 'integer', 'min:1'], 'schema_version' => ['required', 'integer', 'min:1'], 'recorded_at' => ['required', 'date'], 'note' => ['nullable', 'string', 'max:1000'], 'values' => ['required', 'array', 'max:50']],
                'execute' => function (User $actor, array $data): array {
                    $id = (int) $data['entry_id'];
                    unset($data['entry_id']);

                    return $this->container->make(TrackerEntryService::class)->update($actor, $id, $data);
                },
            ],
            'add_vehicle_odometer_reading' => [
                'module' => 'vehicles', 'capability' => 'create', 'title' => 'Add an odometer reading',
                'description' => 'Prepare an odometer reading for member approval.',
                'schema' => ['type' => 'object', 'properties' => ['vehicle_id' => ['type' => 'integer', 'minimum' => 1], 'reading_date' => ['type' => 'string', 'format' => 'date'], 'value' => ['type' => 'string', 'maxLength' => 24], 'note' => ['type' => 'string', 'maxLength' => 2000]], 'required' => ['vehicle_id', 'reading_date', 'value'], 'additionalProperties' => false],
                'rules' => ['vehicle_id' => ['required', 'integer', 'min:1'], 'reading_date' => ['required', 'date_format:Y-m-d'], 'value' => ['required', 'string', 'regex:/\\A(?:0|[1-9]\\d{0,16})(?:\\.\\d{1,3})?\\z/'], 'note' => ['nullable', 'string', 'max:2000']],
                'execute' => function (User $actor, array $data): array {
                    $vehicleId = (int) $data['vehicle_id'];
                    unset($data['vehicle_id']);

                    return $this->container->make(VehicleService::class)->addOdometerReading($actor, $vehicleId, $data);
                },
            ],
            'create_analytics_widget' => [
                'module' => 'analytics', 'capability' => 'create', 'title' => 'Create an analytics widget',
                'description' => 'Prepare a chart from the member\'s currently permitted analytics catalog.',
                'schema' => ['type' => 'object', 'properties' => ['metric_key' => ['type' => 'string', 'maxLength' => 80], 'title' => ['type' => 'string', 'maxLength' => 80], 'range_key' => ['type' => 'string', 'enum' => ['30d', '90d', '12m']], 'chart_type' => ['type' => 'string', 'enum' => ['line', 'bar']]], 'required' => ['metric_key', 'range_key', 'chart_type'], 'additionalProperties' => false],
                'rules' => ['metric_key' => ['required', 'string', 'in:finance.expenses,utilities.consumption,vehicles.distance'], 'title' => ['nullable', 'string', 'max:80'], 'range_key' => ['required', 'in:30d,90d,12m'], 'chart_type' => ['required', 'in:line,bar']],
                'execute' => fn (User $actor, array $data): array => $this->container->make(AnalyticsService::class)->createWidget($actor, $data),
            ],
            'update_analytics_widget' => [
                'module' => 'analytics', 'capability' => 'edit', 'title' => 'Update an analytics widget', 'description' => 'Prepare a version-checked personal analytics widget update for approval.',
                'schema' => ['type' => 'object', 'properties' => ['widget_id' => ['type' => 'integer', 'minimum' => 1], 'expected_version' => ['type' => 'integer', 'minimum' => 1], 'title' => ['type' => 'string', 'maxLength' => 80], 'range_key' => ['type' => 'string', 'enum' => ['30d', '90d', '12m']], 'chart_type' => ['type' => 'string', 'enum' => ['line', 'bar']]], 'required' => ['widget_id', 'expected_version', 'title', 'range_key', 'chart_type'], 'additionalProperties' => false],
                'rules' => ['widget_id' => ['required', 'integer', 'min:1'], 'expected_version' => ['required', 'integer', 'min:1'], 'title' => ['required', 'string', 'max:80'], 'range_key' => ['required', 'in:30d,90d,12m'], 'chart_type' => ['required', 'in:line,bar']],
                'execute' => function (User $actor, array $data): array {
                    $id = (int) $data['widget_id'];
                    unset($data['widget_id']);

                    return $this->container->make(AnalyticsService::class)->updateWidget($actor, $id, $data);
                },
            ],
            'create_household_task' => [
                'module' => 'tasks', 'capability' => 'create', 'title' => 'Create a household task',
                'description' => 'Prepare a private or shared task for member approval.',
                'schema' => ['type' => 'object', 'properties' => ['title' => ['type' => 'string', 'maxLength' => 180], 'description' => ['type' => 'string', 'maxLength' => 4000], 'due_at' => ['type' => 'string', 'format' => 'date-time'], 'timezone' => ['type' => 'string', 'maxLength' => 80], 'priority' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']], 'assignee_id' => ['type' => 'integer', 'minimum' => 1], 'visibility' => ['type' => 'string', 'enum' => ['private', 'household']], 'recurrence_frequency' => ['type' => 'string', 'enum' => ['daily', 'weekly', 'monthly', 'yearly']], 'recurrence_interval' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 99]], 'required' => ['title', 'timezone', 'priority', 'visibility'], 'additionalProperties' => false],
                'rules' => ['title' => ['required', 'string', 'max:180'], 'description' => ['nullable', 'string', 'max:4000'], 'due_at' => ['nullable', 'date'], 'timezone' => ['required', 'timezone'], 'priority' => ['required', 'in:low,medium,high'], 'assignee_id' => ['nullable', 'integer', 'min:1'], 'visibility' => ['required', 'in:private,household'], 'recurrence_frequency' => ['nullable', 'in:daily,weekly,monthly,yearly'], 'recurrence_interval' => ['required', 'integer', 'min:1', 'max:99']],
                'execute' => fn (User $actor, array $data): array => $this->container->make(HouseholdTaskService::class)->create($actor, array_merge(['priority' => 'medium', 'timezone' => 'UTC', 'visibility' => 'private', 'recurrence_interval' => 1], $data)),
            ],
            'update_household_task' => [
                'module' => 'tasks', 'capability' => 'edit', 'title' => 'Update a household task', 'description' => 'Prepare a version-checked task update for member approval.',
                'schema' => ['type' => 'object', 'properties' => ['task_id' => ['type' => 'integer', 'minimum' => 1], 'expected_version' => ['type' => 'integer', 'minimum' => 1], 'title' => ['type' => 'string', 'maxLength' => 180], 'description' => ['type' => 'string', 'maxLength' => 4000], 'due_at' => ['type' => 'string', 'format' => 'date-time'], 'timezone' => ['type' => 'string', 'maxLength' => 80], 'priority' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']], 'assignee_id' => ['type' => 'integer', 'minimum' => 1], 'recurrence_frequency' => ['type' => 'string', 'enum' => ['daily', 'weekly', 'monthly', 'yearly']], 'recurrence_interval' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 99]], 'required' => ['task_id', 'expected_version'], 'additionalProperties' => false],
                'rules' => ['task_id' => ['required', 'integer', 'min:1'], 'expected_version' => ['required', 'integer', 'min:1'], 'title' => ['sometimes', 'required', 'string', 'max:180'], 'description' => ['sometimes', 'nullable', 'string', 'max:4000'], 'due_at' => ['sometimes', 'nullable', 'date'], 'timezone' => ['sometimes', 'required', 'timezone'], 'priority' => ['sometimes', 'required', 'in:low,medium,high'], 'assignee_id' => ['sometimes', 'nullable', 'integer', 'min:1'], 'recurrence_frequency' => ['sometimes', 'nullable', 'in:daily,weekly,monthly,yearly'], 'recurrence_interval' => ['sometimes', 'required', 'integer', 'min:1', 'max:99']],
                'execute' => function (User $actor, array $data): array {
                    $id = (int) $data['task_id'];
                    unset($data['task_id']);

                    return $this->container->make(HouseholdTaskService::class)->update($actor, $id, $data);
                },
            ],
            'delete_household_task' => [
                'module' => 'tasks', 'capability' => 'edit', 'title' => 'Delete a household task', 'description' => 'Prepare task deletion for member approval.',
                'schema' => ['type' => 'object', 'properties' => ['task_id' => ['type' => 'integer', 'minimum' => 1]], 'required' => ['task_id'], 'additionalProperties' => false],
                'rules' => ['task_id' => ['required', 'integer', 'min:1']],
                'execute' => function (User $actor, array $data): array {
                    $this->container->make(HouseholdTaskService::class)->delete($actor, (int) $data['task_id']);

                    return ['status' => 'deleted'];
                },
            ],
            'update_recipe' => [
                'module' => 'recipes', 'capability' => 'edit', 'title' => 'Update a recipe', 'description' => 'Prepare recipe details and ingredients for member approval.',
                'schema' => ['type' => 'object', 'properties' => ['recipe_id' => ['type' => 'integer', 'minimum' => 1], 'title' => ['type' => 'string', 'maxLength' => 180], 'category' => ['type' => 'string', 'maxLength' => 80], 'instructions' => ['type' => 'string', 'maxLength' => 20000], 'servings' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000], 'preparation_minutes' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1440], 'ingredients' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 100, 'items' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string', 'maxLength' => 120], 'quantity' => ['type' => 'string', 'maxLength' => 32], 'unit' => ['type' => 'string', 'maxLength' => 40], 'note' => ['type' => 'string', 'maxLength' => 160]], 'required' => ['name', 'quantity', 'unit'], 'additionalProperties' => false]]], 'required' => ['recipe_id'], 'additionalProperties' => false],
                'rules' => ['recipe_id' => ['required', 'integer', 'min:1'], 'title' => ['sometimes', 'required', 'string', 'max:180'], 'category' => ['sometimes', 'nullable', 'string', 'max:80'], 'instructions' => ['sometimes', 'required', 'string', 'max:20000'], 'servings' => ['sometimes', 'required', 'integer', 'between:1,1000'], 'preparation_minutes' => ['sometimes', 'nullable', 'integer', 'between:1,1440'], 'ingredients' => ['sometimes', 'required', 'array', 'min:1', 'max:100'], 'ingredients.*' => ['required', 'array:name,quantity,unit,note'], 'ingredients.*.name' => ['required', 'string', 'max:120'], 'ingredients.*.quantity' => ['required', 'string', 'max:32'], 'ingredients.*.unit' => ['required', 'string', 'max:40'], 'ingredients.*.note' => ['nullable', 'string', 'max:160']],
                'execute' => function (User $actor, array $data): array {
                    $id = (int) $data['recipe_id'];
                    unset($data['recipe_id']);

                    return $this->container->make(RecipeService::class)->update($actor, $id, $data);
                },
            ],
            'delete_recipe' => [
                'module' => 'recipes', 'capability' => 'edit', 'title' => 'Delete a recipe', 'description' => 'Prepare deletion of a recipe for member approval.',
                'schema' => ['type' => 'object', 'properties' => ['recipe_id' => ['type' => 'integer', 'minimum' => 1]], 'required' => ['recipe_id'], 'additionalProperties' => false],
                'rules' => ['recipe_id' => ['required', 'integer', 'min:1']],
                'execute' => function (User $actor, array $data): array {
                    $this->container->make(RecipeService::class)->delete($actor, (int) $data['recipe_id']);

                    return ['status' => 'deleted'];
                },
            ],
            'delete_meal_plan_slot' => [
                'module' => 'meal_planning', 'capability' => 'edit', 'title' => 'Remove a planned meal', 'description' => 'Prepare removal of a meal plan slot for member approval.',
                'schema' => ['type' => 'object', 'properties' => ['slot_id' => ['type' => 'integer', 'minimum' => 1]], 'required' => ['slot_id'], 'additionalProperties' => false],
                'rules' => ['slot_id' => ['required', 'integer', 'min:1']],
                'execute' => function (User $actor, array $data): array {
                    $this->container->make(MealPlanningService::class)->delete($actor, (int) $data['slot_id']);

                    return ['status' => 'deleted'];
                },
            ],
            'adjust_pantry_stock' => [
                'module' => 'pantry', 'capability' => 'edit', 'title' => 'Adjust pantry stock', 'description' => 'Prepare a stock adjustment for member approval.',
                'schema' => ['type' => 'object', 'properties' => ['item_id' => ['type' => 'integer', 'minimum' => 1], 'expected_version' => ['type' => 'integer', 'minimum' => 1], 'quantity_delta' => ['type' => 'string', 'maxLength' => 24], 'note' => ['type' => 'string', 'maxLength' => 500]], 'required' => ['item_id', 'expected_version', 'quantity_delta'], 'additionalProperties' => false],
                'rules' => ['item_id' => ['required', 'integer', 'min:1'], 'expected_version' => ['required', 'integer', 'min:1'], 'quantity_delta' => ['required', 'string', 'regex:/\\A-?(?:0|[1-9]\\d{0,10})(?:\\.\\d{1,3})?\\z/'], 'note' => ['nullable', 'string', 'max:500']],
                'execute' => function (User $actor, array $data): array {
                    $id = (int) $data['item_id'];
                    unset($data['item_id']);

                    return $this->container->make(PantryService::class)->adjust($actor, $id, $data);
                },
            ],
            'create_shopping_item' => [
                'module' => 'shopping', 'capability' => 'create', 'title' => 'Add a shopping item', 'description' => 'Prepare a shopping list item for member approval.',
                'schema' => ['type' => 'object', 'properties' => ['list_id' => ['type' => 'integer', 'minimum' => 1], 'name' => ['type' => 'string', 'maxLength' => 180], 'quantity' => ['type' => 'string', 'maxLength' => 24], 'unit' => ['type' => 'string', 'maxLength' => 32]], 'required' => ['list_id', 'name'], 'additionalProperties' => false],
                'rules' => ['list_id' => ['required', 'integer', 'min:1'], 'name' => ['required', 'string', 'max:180'], 'quantity' => ['nullable', 'string', 'regex:/\\A(?:0|[1-9]\\d{0,10})(?:\\.\\d{1,3})?\\z/'], 'unit' => ['nullable', 'string', 'max:32']],
                'execute' => function (User $actor, array $data): array {
                    $id = (int) $data['list_id'];
                    unset($data['list_id']);

                    return $this->container->make(ShoppingService::class)->createItem($actor, $id, $data);
                },
            ],
            'update_shopping_item' => [
                'module' => 'shopping', 'capability' => 'edit', 'title' => 'Update a shopping item', 'description' => 'Prepare a version-checked shopping item update for member approval.',
                'schema' => ['type' => 'object', 'properties' => ['item_id' => ['type' => 'integer', 'minimum' => 1], 'expected_version' => ['type' => 'integer', 'minimum' => 1], 'name' => ['type' => 'string', 'maxLength' => 180], 'quantity' => ['type' => 'string', 'maxLength' => 24], 'unit' => ['type' => 'string', 'maxLength' => 32], 'sort_order' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 2147483647]], 'required' => ['item_id', 'expected_version'], 'additionalProperties' => false],
                'rules' => ['item_id' => ['required', 'integer', 'min:1'], 'expected_version' => ['required', 'integer', 'min:1'], 'name' => ['sometimes', 'required', 'string', 'max:180'], 'quantity' => ['sometimes', 'nullable', 'string', 'regex:/\\A(?:0|[1-9]\\d{0,10})(?:\\.\\d{1,3})?\\z/'], 'unit' => ['sometimes', 'nullable', 'string', 'max:32'], 'sort_order' => ['sometimes', 'integer', 'min:0', 'max:2147483647']],
                'execute' => function (User $actor, array $data): array {
                    $id = (int) $data['item_id'];
                    unset($data['item_id']);

                    return $this->container->make(ShoppingService::class)->updateItem($actor, $id, $data);
                },
            ],
            'move_shopping_item' => [
                'module' => 'shopping', 'capability' => 'edit', 'title' => 'Move a shopping item', 'description' => 'Prepare a version-checked shopping list reorder for member approval.',
                'schema' => ['type' => 'object', 'properties' => ['item_id' => ['type' => 'integer', 'minimum' => 1], 'expected_version' => ['type' => 'integer', 'minimum' => 1], 'direction' => ['type' => 'string', 'enum' => ['up', 'down']]], 'required' => ['item_id', 'expected_version', 'direction'], 'additionalProperties' => false],
                'rules' => ['item_id' => ['required', 'integer', 'min:1'], 'expected_version' => ['required', 'integer', 'min:1'], 'direction' => ['required', 'in:up,down']],
                'execute' => function (User $actor, array $data): array {
                    $id = (int) $data['item_id'];
                    unset($data['item_id']);
                    $this->container->make(ShoppingService::class)->moveItem($actor, $id, $data);

                    return ['status' => 'moved'];
                },
            ],
            'set_shopping_item_checked' => [
                'module' => 'shopping', 'capability' => 'edit', 'title' => 'Check off a shopping item', 'description' => 'Prepare a reversible check-off for member approval.',
                'schema' => ['type' => 'object', 'properties' => ['item_id' => ['type' => 'integer', 'minimum' => 1], 'expected_version' => ['type' => 'integer', 'minimum' => 1], 'checked' => ['type' => 'boolean']], 'required' => ['item_id', 'expected_version', 'checked'], 'additionalProperties' => false],
                'rules' => ['item_id' => ['required', 'integer', 'min:1'], 'expected_version' => ['required', 'integer', 'min:1'], 'checked' => ['required', 'boolean']],
                'execute' => function (User $actor, array $data): array {
                    $id = (int) $data['item_id'];
                    unset($data['item_id']);

                    return $this->container->make(ShoppingService::class)->setChecked($actor, $id, $data);
                },
            ],
            'delete_shopping_item' => [
                'module' => 'shopping', 'capability' => 'edit', 'title' => 'Remove a shopping item', 'description' => 'Prepare removal of a shopping list item for member approval.',
                'schema' => ['type' => 'object', 'properties' => ['item_id' => ['type' => 'integer', 'minimum' => 1]], 'required' => ['item_id'], 'additionalProperties' => false],
                'rules' => ['item_id' => ['required', 'integer', 'min:1']],
                'execute' => function (User $actor, array $data): array {
                    $this->container->make(ShoppingService::class)->deleteItem($actor, (int) $data['item_id']);

                    return ['status' => 'deleted'];
                },
            ],
            'delete_financial_transaction' => [
                'module' => 'finance', 'capability' => 'edit', 'title' => 'Delete a financial transaction', 'description' => 'Prepare deletion of a visible financial transaction for member approval.',
                'schema' => ['type' => 'object', 'properties' => ['transaction_id' => ['type' => 'integer', 'minimum' => 1]], 'required' => ['transaction_id'], 'additionalProperties' => false],
                'rules' => ['transaction_id' => ['required', 'integer', 'min:1']],
                'execute' => function (User $actor, array $data): array {
                    $this->container->make(FinanceService::class)->deleteTransaction($actor, (int) $data['transaction_id']);

                    return ['status' => 'deleted'];
                },
            ],
            'delete_tracker_entry' => [
                'module' => 'tracking', 'capability' => 'edit', 'title' => 'Delete a tracker entry', 'description' => 'Prepare deletion of a tracker entry for member approval.',
                'schema' => ['type' => 'object', 'properties' => ['entry_id' => ['type' => 'integer', 'minimum' => 1]], 'required' => ['entry_id'], 'additionalProperties' => false],
                'rules' => ['entry_id' => ['required', 'integer', 'min:1']],
                'execute' => function (User $actor, array $data): array {
                    $this->container->make(TrackerEntryService::class)->delete($actor, (int) $data['entry_id']);

                    return ['status' => 'deleted'];
                },
            ],
            'correct_vehicle_odometer_reading' => [
                'module' => 'vehicles', 'capability' => 'edit', 'title' => 'Correct an odometer reading', 'description' => 'Prepare an odometer correction for member approval.',
                'schema' => ['type' => 'object', 'properties' => ['reading_id' => ['type' => 'integer', 'minimum' => 1], 'reading_date' => ['type' => 'string', 'format' => 'date'], 'value' => ['type' => 'string', 'maxLength' => 24], 'note' => ['type' => 'string', 'maxLength' => 2000], 'reason' => ['type' => 'string', 'maxLength' => 500]], 'required' => ['reading_id', 'reading_date', 'value', 'note'], 'additionalProperties' => false],
                'rules' => ['reading_id' => ['required', 'integer', 'min:1'], 'reading_date' => ['required', 'date_format:Y-m-d'], 'value' => ['required', 'string', 'regex:/\\A(?:0|[1-9]\\d{0,16})(?:\\.\\d{1,3})?\\z/'], 'note' => ['present', 'nullable', 'string', 'max:2000'], 'reason' => ['nullable', 'string', 'max:500']],
                'execute' => function (User $actor, array $data): array {
                    $id = (int) $data['reading_id'];
                    unset($data['reading_id']);

                    return $this->container->make(VehicleService::class)->correctOdometerReading($actor, $id, $data);
                },
            ],
            'delete_analytics_widget' => [
                'module' => 'analytics', 'capability' => 'edit', 'title' => 'Delete an analytics widget', 'description' => 'Prepare removal of a personal analytics widget for member approval.',
                'schema' => ['type' => 'object', 'properties' => ['widget_id' => ['type' => 'integer', 'minimum' => 1]], 'required' => ['widget_id'], 'additionalProperties' => false],
                'rules' => ['widget_id' => ['required', 'integer', 'min:1']],
                'execute' => function (User $actor, array $data): array {
                    $this->container->make(AnalyticsService::class)->deleteWidget($actor, (int) $data['widget_id']);

                    return ['status' => 'deleted'];
                },
            ],
            'complete_household_task' => [
                'module' => 'tasks', 'capability' => 'edit', 'title' => 'Complete a household task', 'description' => 'Prepare task completion for member approval.',
                'schema' => ['type' => 'object', 'properties' => ['task_id' => ['type' => 'integer', 'minimum' => 1], 'expected_version' => ['type' => 'integer', 'minimum' => 1]], 'required' => ['task_id', 'expected_version'], 'additionalProperties' => false],
                'rules' => ['task_id' => ['required', 'integer', 'min:1'], 'expected_version' => ['required', 'integer', 'min:1']],
                'execute' => function (User $actor, array $data): array {
                    $id = (int) $data['task_id'];
                    unset($data['task_id']);

                    return $this->container->make(HouseholdTaskService::class)->complete($actor, $id, $data);
                },
            ],
            'correct_utility_reading' => [
                'module' => 'utilities', 'capability' => 'edit', 'title' => 'Correct a utility reading', 'description' => 'Prepare a dated meter reading correction for member approval.',
                'schema' => ['type' => 'object', 'properties' => ['reading_id' => ['type' => 'integer', 'minimum' => 1], 'reading_date' => ['type' => 'string', 'format' => 'date'], 'value' => ['type' => 'string', 'maxLength' => 24], 'note' => ['type' => 'string', 'maxLength' => 2000], 'reason' => ['type' => 'string', 'maxLength' => 500]], 'required' => ['reading_id', 'reading_date', 'value', 'note'], 'additionalProperties' => false],
                'rules' => ['reading_id' => ['required', 'integer', 'min:1'], 'reading_date' => ['required', 'date_format:Y-m-d'], 'value' => ['required', 'string', 'regex:/\\A(?:0|[1-9]\\d{0,13})(?:\\.\\d{1,6})?\\z/'], 'note' => ['present', 'nullable', 'string', 'max:2000'], 'reason' => ['nullable', 'string', 'max:500']],
                'execute' => function (User $actor, array $data): array {
                    $id = (int) $data['reading_id'];
                    unset($data['reading_id']);

                    return $this->container->make(UtilityService::class)->correctReading($actor, $id, $data);
                },
            ],
        ];
    }

    /** @return list<array{name: string, title: string, description: string, module: string, capability: string, capabilities: list<string>, inputSchema: array<string, mixed>}> */
    public function all(): array
    {
        return array_map(fn (string $name, array $workflow): array => [
            'name' => 'preview_'.$name,
            'title' => $workflow['title'],
            'description' => $workflow['description'],
            'module' => $workflow['module'],
            'capability' => $workflow['capability'],
            'capabilities' => array_values(array_unique([$workflow['capability'], 'manage'])),
            'inputSchema' => $workflow['schema'],
        ], array_keys($this->workflows), array_values($this->workflows));
    }

    /** @return WorkflowDefinition */
    public function get(string $name): array
    {
        $workflow = $this->workflows[$name] ?? throw new \InvalidArgumentException('Unknown MCP workflow.');

        $workflow['capabilities'] = array_values(array_unique([$workflow['capability'], 'manage']));

        return $workflow;
    }

    /** @param array<string, mixed> $arguments
     * @return list<string>
     */
    public function requiredCapabilities(string $name, array $arguments): array
    {
        $workflow = $this->get($name);
        if (($arguments['visibility'] ?? null) === 'household') {
            return ['manage'];
        }

        $target = match ($name) {
            'create_shopping_item', 'update_shopping_list' => ShoppingList::query()->find($arguments['list_id'] ?? null),
            'update_finance_category', 'delete_finance_category' => FinanceCategory::query()->find($arguments['category_id'] ?? null),
            'update_finance_budget', 'delete_finance_budget' => FinanceBudget::query()->find($arguments['budget_id'] ?? null),
            'update_finance_recurring_entry', 'delete_finance_recurring_entry', 'record_finance_recurring_occurrence' => FinanceRecurringEntry::query()->find($arguments['entry_id'] ?? null),
            'update_recipe', 'delete_recipe' => Recipe::query()->find($arguments['recipe_id'] ?? null),
            'update_tracker_schema', 'delete_tracker' => TrackerDefinition::query()->find($arguments['tracker_id'] ?? null),
            'update_meal_plan_slot', 'delete_meal_plan_slot' => MealPlanSlot::query()->find($arguments['slot_id'] ?? null),
            'update_utility_meter' => UtilityMeter::query()->find($arguments['meter_id'] ?? null),
            'update_vehicle' => Vehicle::query()->find($arguments['vehicle_id'] ?? null),
            'add_vehicle_maintenance', 'add_vehicle_insurance', 'add_vehicle_cost' => Vehicle::query()->find($arguments['vehicle_id'] ?? null),
            'update_pantry_item', 'adjust_pantry_stock' => PantryItem::query()->find($arguments['item_id'] ?? null),
            'update_financial_transaction', 'delete_financial_transaction' => FinancialTransaction::query()->find($arguments['transaction_id'] ?? null),
            'update_shopping_item', 'move_shopping_item', 'set_shopping_item_checked', 'delete_shopping_item' => ShoppingItem::query()->with('shoppingList')->find($arguments['item_id'] ?? null)?->shoppingList,
            default => null,
        };

        if ($target instanceof ModuleResource && ! $target->isPrivate()
            && in_array($workflow['module'], ['recipes', 'meal_planning', 'pantry', 'shopping', 'finance'], true)) {
            return ['manage'];
        }

        return [$workflow['capability']];
    }

    /** @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function validate(string $name, array $arguments): array
    {
        $workflow = $this->get($name);
        $properties = array_keys($workflow['schema']['properties']);
        if (array_diff(array_keys($arguments), $properties) !== []) {
            throw ValidationException::withMessages(['arguments' => 'Unexpected MCP workflow arguments.']);
        }

        $validated = Validator::make($arguments, $workflow['rules'])->validate();
        if (in_array($name, ['add_tracker_entry', 'update_tracker_entry'], true)) {
            foreach ($validated['values'] as $value) {
                if (! is_scalar($value) && $value !== null) {
                    throw ValidationException::withMessages(['values' => 'Tracker values must be scalar.']);
                }
            }
        }
        if (in_array($name, ['create_recipe', 'update_recipe'], true) && isset($validated['ingredients'])) {
            foreach ($validated['ingredients'] as $index => $ingredient) {
                if (RecipeQuantity::normalize($ingredient['quantity'] ?? null) === null) {
                    throw ValidationException::withMessages(["ingredients.{$index}.quantity" => 'Enter a positive recipe quantity.']);
                }
            }
        }
        if ($name === 'create_financial_transaction' && FinanceDecimalValue::money($validated['amount'] ?? null) === null) {
            throw ValidationException::withMessages(['amount' => 'Enter a positive transaction amount.']);
        }
        if ($name === 'add_vehicle_odometer_reading' && VehicleDecimalValue::distance($validated['value'] ?? null) === null) {
            throw ValidationException::withMessages(['value' => 'Enter a valid odometer reading.']);
        }
        if ($name === 'correct_vehicle_odometer_reading' && VehicleDecimalValue::distance($validated['value'] ?? null) === null) {
            throw ValidationException::withMessages(['value' => 'Enter a valid odometer reading.']);
        }
        if ($name === 'correct_utility_reading' && DecimalValue::normalize($validated['value'] ?? null) === null) {
            throw ValidationException::withMessages(['value' => 'Enter a valid utility reading.']);
        }
        if ($name === 'adjust_pantry_stock' && PantryQuantity::delta($validated['quantity_delta'] ?? null) === null) {
            throw ValidationException::withMessages(['quantity_delta' => 'Enter a non-zero stock adjustment.']);
        }
        if ($name === 'update_recipe' && count(array_diff(array_keys($validated), ['recipe_id'])) === 0) {
            throw ValidationException::withMessages(['arguments' => 'Provide at least one recipe field to update.']);
        }
        $requiredOnly = [
            'update_meal_plan_slot' => ['slot_id'],
            'update_pantry_item' => ['item_id', 'expected_version'],
            'update_shopping_item' => ['item_id', 'expected_version'],
            'update_household_task' => ['task_id', 'expected_version'],
        ][$name] ?? null;
        if ($requiredOnly !== null && count(array_diff(array_keys($validated), $requiredOnly)) === 0) {
            throw ValidationException::withMessages(['arguments' => 'Provide at least one field to update.']);
        }
        if ($name === 'update_financial_transaction' && isset($validated['amount']) && FinanceDecimalValue::money($validated['amount']) === null) {
            throw ValidationException::withMessages(['amount' => 'Enter a positive transaction amount.']);
        }

        return $validated;
    }

    /** @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function execute(string $name, User $actor, array $arguments): array
    {
        $workflow = $this->get($name);
        $result = ($workflow['execute'])($actor, $arguments);

        return is_array($result) ? $result : ['status' => 'completed'];
    }

    /** Recheck referenced private resources before approval and again before execution. */
    public function authorizeResources(string $name, User $actor, array $arguments): void
    {
        match ($name) {
            'create_financial_transaction' => $this->authorizeResource($actor, 'finance', 'create', FinanceCategory::class, (int) $arguments['finance_category_id']),
            'add_tracker_entry' => $this->authorizeResource($actor, 'tracking', 'create', TrackerDefinition::class, (int) $arguments['tracker_id']),
            'add_vehicle_odometer_reading' => $this->authorizeResource($actor, 'vehicles', 'create', Vehicle::class, (int) $arguments['vehicle_id']),
            'update_vehicle' => $this->authorizeResource($actor, 'vehicles', 'manage', Vehicle::class, (int) $arguments['vehicle_id']),
            'add_vehicle_maintenance', 'add_vehicle_insurance', 'add_vehicle_cost' => $this->authorizeResource($actor, 'vehicles', 'create', Vehicle::class, (int) $arguments['vehicle_id']),
            'create_shopping_item' => $this->authorizeResource($actor, 'shopping', 'create', ShoppingList::class, (int) $arguments['list_id']),
            'update_recipe', 'delete_recipe' => $this->authorizeResource($actor, 'recipes', 'edit', Recipe::class, (int) $arguments['recipe_id']),
            'update_meal_plan_slot', 'delete_meal_plan_slot' => $this->authorizeResource($actor, 'meal_planning', 'edit', MealPlanSlot::class, (int) $arguments['slot_id']),
            'update_pantry_item', 'adjust_pantry_stock' => $this->authorizeResource($actor, 'pantry', 'edit', PantryItem::class, (int) $arguments['item_id']),
            'update_shopping_list' => $this->authorizeResource($actor, 'shopping', 'edit', ShoppingList::class, (int) $arguments['list_id']),
            'update_shopping_item', 'move_shopping_item', 'set_shopping_item_checked', 'delete_shopping_item' => $this->authorizeShoppingItem($actor, (int) $arguments['item_id']),
            'update_financial_transaction', 'delete_financial_transaction' => $this->authorizeResource($actor, 'finance', 'edit', FinancialTransaction::class, (int) $arguments['transaction_id']),
            'update_finance_category', 'delete_finance_category' => $this->authorizeResource($actor, 'finance', 'edit', FinanceCategory::class, (int) $arguments['category_id']),
            'update_finance_budget', 'delete_finance_budget' => $this->authorizeResource($actor, 'finance', 'edit', FinanceBudget::class, (int) $arguments['budget_id']),
            'update_finance_recurring_entry', 'delete_finance_recurring_entry' => $this->authorizeResource($actor, 'finance', 'edit', FinanceRecurringEntry::class, (int) $arguments['entry_id']),
            'record_finance_recurring_occurrence' => $this->authorizeResource($actor, 'finance', 'create', FinanceRecurringEntry::class, (int) $arguments['entry_id']),
            'update_tracker_entry', 'delete_tracker_entry' => $this->authorizeTrackerEntry($actor, (int) $arguments['entry_id']),
            'update_tracker_schema', 'delete_tracker' => $this->authorizeResource($actor, 'tracking', 'manage', TrackerDefinition::class, (int) $arguments['tracker_id']),
            'correct_vehicle_odometer_reading' => $this->authorizeVehicleReading($actor, (int) $arguments['reading_id']),
            'correct_utility_reading' => $this->authorizeUtilityReading($actor, (int) $arguments['reading_id']),
            'update_utility_meter' => $this->authorizeResource($actor, 'utilities', 'manage', UtilityMeter::class, (int) $arguments['meter_id']),
            'update_analytics_widget', 'delete_analytics_widget' => $this->authorizeWidget($actor, (int) $arguments['widget_id']),
            'update_household_task', 'complete_household_task', 'delete_household_task' => $this->authorizeResource($actor, 'tasks', 'edit', HouseholdTask::class, (int) $arguments['task_id']),
            'create_meal_plan_slot' => $this->authorizeMealRecipe($actor, $arguments),
            'create_analytics_widget' => $this->authorizeMetric($actor, (string) $arguments['metric_key']),
            default => null,
        };
    }

    /** @param array<string, mixed> $arguments
     * @return list<string>
     */
    public function additionalTokenScopes(string $name, array $arguments): array
    {
        if (in_array($name, ['create_meal_plan_slot', 'update_meal_plan_slot', 'favorite_recipe', 'unfavorite_recipe'], true)
            && (isset($arguments['recipe_id']) || (($arguments['dish_type'] ?? null) === 'recipe' && $name === 'create_meal_plan_slot'))) {
            return ['recipes:view'];
        }

        if ($name === 'create_analytics_widget') {
            $source = match ($arguments['metric_key'] ?? null) {
                'finance.expenses' => 'finance',
                'utilities.consumption' => 'utilities',
                'vehicles.distance' => 'vehicles',
                default => null,
            };

            return $source === null ? [] : [$source.':view'];
        }

        return [];
    }

    /** @param class-string<Model> $model */
    private function authorizeResource(User $actor, string $module, string $capability, string $model, int $id): void
    {
        $resource = $model::query()->whereKey($id)->firstOrFail();
        $enum = ModuleCapability::from($capability);

        try {
            $this->access->authorize($actor, $module, $enum, $resource);
        } catch (AuthorizationException|ModuleDisabled $exception) {
            $this->access->authorize($actor, $module, ModuleCapability::Manage, $resource);
        }
    }

    private function authorizeShoppingItem(User $actor, int $itemId): void
    {
        $item = ShoppingItem::query()->with('shoppingList')->whereKey($itemId)->firstOrFail();
        $this->authorizeResource($actor, 'shopping', 'edit', ShoppingList::class, (int) $item->shopping_list_id);
    }

    private function authorizeTrackerEntry(User $actor, int $entryId): void
    {
        $entry = TrackerEntry::query()->with('tracker')->whereKey($entryId)->firstOrFail();
        $this->authorizeResource($actor, 'tracking', 'edit', TrackerEntry::class, $entryId);
        $this->authorizeResource($actor, 'tracking', 'edit', TrackerDefinition::class, (int) $entry->tracker_definition_id);
    }

    private function authorizeVehicleReading(User $actor, int $readingId): void
    {
        $reading = VehicleOdometerReading::query()->with('vehicle')->whereKey($readingId)->firstOrFail();
        $this->authorizeResource($actor, 'vehicles', 'edit', VehicleOdometerReading::class, $readingId);
        $this->authorizeResource($actor, 'vehicles', 'edit', Vehicle::class, (int) $reading->vehicle_id);
    }

    private function authorizeUtilityReading(User $actor, int $readingId): void
    {
        $reading = UtilityReading::query()->with('meter')->whereKey($readingId)->firstOrFail();
        $this->authorizeResource($actor, 'utilities', 'edit', UtilityReading::class, $readingId);
        $this->authorizeResource($actor, 'utilities', 'edit', UtilityMeter::class, (int) $reading->utility_meter_id);
    }

    private function authorizeWidget(User $actor, int $widgetId): void
    {
        AnalyticsWidget::query()->where('user_id', $actor->id)->whereKey($widgetId)->firstOrFail();
        $this->access->authorize($actor, 'analytics', 'edit');
    }

    /** @param array<string, mixed> $arguments */
    private function authorizeMealRecipe(User $actor, array $arguments): void
    {
        if (($arguments['dish_type'] ?? null) === 'recipe') {
            $this->container->make(RecipeService::class)->findForMealPlan($actor, (int) $arguments['recipe_id']);
        }
    }

    private function authorizeMetric(User $actor, string $metricKey): void
    {
        $available = $this->container->make(AnalyticsService::class)->catalog($actor);
        abort_unless(collect($available)->contains(fn (array $metric): bool => $metric['key'] === $metricKey), 404);
    }
}
