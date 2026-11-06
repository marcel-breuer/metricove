<?php

namespace App\Services\Mcp;

use App\Models\User;
use App\Services\Analytics\AnalyticsService;
use App\Services\Finance\FinanceService;
use App\Services\MealPlanning\MealPlanningService;
use App\Services\Pantry\PantryService;
use App\Services\Recipes\RecipeService;
use App\Services\Shopping\ShoppingService;
use App\Services\Tasks\HouseholdTaskService;
use App\Services\Tracking\TrackerEntryService;
use App\Services\Tracking\TrackerSchemaService;
use App\Services\Utilities\UtilityService;
use App\Services\Vehicles\VehicleService;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Explicit catalog of bounded reads backed by existing module services. */
class McpDomainReadWorkflowRegistry
{
    /** @var array<string, array<string, mixed>> */
    private array $reads;

    public function __construct(private readonly Container $container)
    {
        $this->reads = [
            'show_current_member_profile' => [
                'module' => 'core', 'scopes' => ['core:profile:view'], 'title' => 'Show your Core profile',
                'description' => 'Show the authenticated token owner’s basic profile and preferences. It never returns credentials or other members’ data.',
                'schema' => ['type' => 'object', 'properties' => new \stdClass, 'additionalProperties' => false], 'rules' => [],
                'execute' => fn (User $actor): array => ['profile' => [
                    'name' => $actor->name,
                    'language' => $actor->language,
                    'number_locale' => $actor->number_locale,
                    'default_currency' => $actor->default_currency,
                    'currency_display' => $actor->currency_display,
                ]],
            ],
            'show_visible_recipe' => [
                'module' => 'recipes', 'scopes' => ['recipes:view'], 'title' => 'Show a visible recipe',
                'description' => 'Show one authorized recipe with its ingredient list and preparation steps.',
                'schema' => ['type' => 'object', 'properties' => ['recipe_id' => ['type' => 'integer', 'minimum' => 1]], 'required' => ['recipe_id'], 'additionalProperties' => false],
                'rules' => ['recipe_id' => ['required', 'integer', 'min:1']],
                'execute' => fn (User $actor, array $data): array => $this->container->make(RecipeService::class)->show($actor, (int) $data['recipe_id']),
            ],
            'list_meal_plan_week' => [
                'module' => 'meal_planning', 'scopes' => ['meal_planning:view', 'recipes:view'], 'title' => 'List a visible meal plan week',
                'description' => 'Show a Monday-to-Sunday meal plan week. Recipe-linked slots also require Recipes view access.',
                'schema' => ['type' => 'object', 'properties' => ['week_start' => ['type' => 'string', 'format' => 'date']], 'required' => ['week_start'], 'additionalProperties' => false],
                'rules' => ['week_start' => ['required', 'date_format:Y-m-d']],
                'execute' => fn (User $actor, array $data): array => $this->container->make(MealPlanningService::class)->week($actor, $data['week_start']),
            ],
            'list_visible_tracker_schemas' => [
                'module' => 'tracking', 'scopes' => ['tracking:view'], 'title' => 'List visible tracker schemas',
                'description' => 'List tracker definitions visible to the member, without private entry values.',
                'schema' => ['type' => 'object', 'properties' => new \stdClass, 'additionalProperties' => false], 'rules' => [],
                'execute' => fn (User $actor): array => ['trackers' => $this->container->make(TrackerSchemaService::class)->index($actor)],
            ],
            'show_visible_tracker_schema' => [
                'module' => 'tracking', 'scopes' => ['tracking:view'], 'title' => 'Show a visible tracker schema',
                'description' => 'Show one authorized tracker definition and its bounded schema revision history.',
                'schema' => ['type' => 'object', 'properties' => ['tracker_id' => ['type' => 'integer', 'minimum' => 1]], 'required' => ['tracker_id'], 'additionalProperties' => false],
                'rules' => ['tracker_id' => ['required', 'integer', 'min:1']],
                'execute' => fn (User $actor, array $data): array => $this->container->make(TrackerSchemaService::class)->show($actor, (int) $data['tracker_id']),
            ],
            'list_visible_tracker_entries' => [
                'module' => 'tracking', 'scopes' => ['tracking:view'], 'title' => 'List visible tracker entries',
                'description' => 'List a bounded page of authorized entries using the current typed tracker schema.',
                'schema' => ['type' => 'object', 'properties' => ['tracker_id' => ['type' => 'integer', 'minimum' => 1], 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50], 'before_recorded_at' => ['type' => 'string', 'format' => 'date-time'], 'before_id' => ['type' => 'integer', 'minimum' => 1]], 'required' => ['tracker_id'], 'additionalProperties' => false],
                'rules' => ['tracker_id' => ['required', 'integer', 'min:1'], 'limit' => ['nullable', 'integer', 'between:1,50'], 'before_recorded_at' => ['nullable', 'date'], 'before_id' => ['nullable', 'integer', 'min:1']],
                'execute' => fn (User $actor, array $data): array => $this->container->make(TrackerEntryService::class)->history($actor, (int) $data['tracker_id'], (int) ($data['limit'] ?? 30), $data['before_recorded_at'] ?? null, isset($data['before_id']) ? (int) $data['before_id'] : null),
            ],
            'list_visible_finance_categories' => [
                'module' => 'finance', 'scopes' => ['finance:view'], 'title' => 'List visible finance categories',
                'description' => 'List finance categories currently visible to the member.',
                'schema' => ['type' => 'object', 'properties' => new \stdClass, 'additionalProperties' => false], 'rules' => [],
                'execute' => fn (User $actor): array => ['categories' => $this->container->make(FinanceService::class)->categories($actor)],
            ],
            'show_visible_utility_meter' => [
                'module' => 'utilities', 'scopes' => ['utilities:view'], 'title' => 'Show a visible utility meter',
                'description' => 'Show one authorized meter with its bounded readings and usage summary.',
                'schema' => ['type' => 'object', 'properties' => ['meter_id' => ['type' => 'integer', 'minimum' => 1]], 'required' => ['meter_id'], 'additionalProperties' => false],
                'rules' => ['meter_id' => ['required', 'integer', 'min:1']],
                'execute' => fn (User $actor, array $data): array => $this->container->make(UtilityService::class)->show($actor, (int) $data['meter_id']),
            ],
            'list_visible_utility_reading_revisions' => [
                'module' => 'utilities', 'scopes' => ['utilities:view'], 'title' => 'List visible utility reading revisions',
                'description' => 'List the bounded audit history for one authorized reading.',
                'schema' => ['type' => 'object', 'properties' => ['reading_id' => ['type' => 'integer', 'minimum' => 1]], 'required' => ['reading_id'], 'additionalProperties' => false],
                'rules' => ['reading_id' => ['required', 'integer', 'min:1']],
                'execute' => fn (User $actor, array $data): array => ['revisions' => $this->container->make(UtilityService::class)->revisions($actor, (int) $data['reading_id'])],
            ],
            'show_visible_vehicle' => [
                'module' => 'vehicles', 'scopes' => ['vehicles:view'], 'title' => 'Show a visible vehicle',
                'description' => 'Show one authorized vehicle with bounded odometer, maintenance, insurance and cost records.',
                'schema' => ['type' => 'object', 'properties' => ['vehicle_id' => ['type' => 'integer', 'minimum' => 1]], 'required' => ['vehicle_id'], 'additionalProperties' => false],
                'rules' => ['vehicle_id' => ['required', 'integer', 'min:1']],
                'execute' => fn (User $actor, array $data): array => $this->container->make(VehicleService::class)->show($actor, (int) $data['vehicle_id']),
            ],
            'list_visible_vehicle_odometer_revisions' => [
                'module' => 'vehicles', 'scopes' => ['vehicles:view'], 'title' => 'List visible odometer revisions',
                'description' => 'List the bounded audit history for one authorized odometer reading.',
                'schema' => ['type' => 'object', 'properties' => ['reading_id' => ['type' => 'integer', 'minimum' => 1]], 'required' => ['reading_id'], 'additionalProperties' => false],
                'rules' => ['reading_id' => ['required', 'integer', 'min:1']],
                'execute' => fn (User $actor, array $data): array => ['revisions' => $this->container->make(VehicleService::class)->odometerRevisions($actor, (int) $data['reading_id'])],
            ],
            'list_visible_pantry_movements' => [
                'module' => 'pantry', 'scopes' => ['pantry:view'], 'title' => 'List visible pantry movements',
                'description' => 'List the bounded stock movement history for one authorized pantry item.',
                'schema' => ['type' => 'object', 'properties' => ['item_id' => ['type' => 'integer', 'minimum' => 1]], 'required' => ['item_id'], 'additionalProperties' => false],
                'rules' => ['item_id' => ['required', 'integer', 'min:1']],
                'execute' => fn (User $actor, array $data): array => ['movements' => $this->container->make(PantryService::class)->movements($actor, (int) $data['item_id'])],
            ],
            'show_visible_shopping_list' => [
                'module' => 'shopping', 'scopes' => ['shopping:view'], 'title' => 'Show a visible shopping list',
                'description' => 'Show one authorized shopping list and its currently visible items.',
                'schema' => ['type' => 'object', 'properties' => ['list_id' => ['type' => 'integer', 'minimum' => 1]], 'required' => ['list_id'], 'additionalProperties' => false],
                'rules' => ['list_id' => ['required', 'integer', 'min:1']],
                'execute' => fn (User $actor, array $data): array => $this->container->make(ShoppingService::class)->show($actor, (int) $data['list_id']),
            ],
            'list_visible_shopping_history' => [
                'module' => 'shopping', 'scopes' => ['shopping:view'], 'title' => 'List visible shopping history',
                'description' => 'List the bounded activity history for one authorized shopping list.',
                'schema' => ['type' => 'object', 'properties' => ['list_id' => ['type' => 'integer', 'minimum' => 1]], 'required' => ['list_id'], 'additionalProperties' => false],
                'rules' => ['list_id' => ['required', 'integer', 'min:1']],
                'execute' => fn (User $actor, array $data): array => ['events' => $this->container->make(ShoppingService::class)->history($actor, (int) $data['list_id'])],
            ],
            'list_visible_task_assignees' => [
                'module' => 'tasks', 'scopes' => ['tasks:view'], 'title' => 'List task assignees',
                'description' => 'List active household members who can be assigned to a visible task.',
                'schema' => ['type' => 'object', 'properties' => new \stdClass, 'additionalProperties' => false], 'rules' => [],
                'execute' => fn (User $actor): array => ['assignees' => $this->container->make(HouseholdTaskService::class)->assignees($actor)],
            ],
            'list_visible_task_history' => [
                'module' => 'tasks', 'scopes' => ['tasks:view'], 'title' => 'List visible task history',
                'description' => 'List the bounded completion history for one authorized task.',
                'schema' => ['type' => 'object', 'properties' => ['task_id' => ['type' => 'integer', 'minimum' => 1]], 'required' => ['task_id'], 'additionalProperties' => false],
                'rules' => ['task_id' => ['required', 'integer', 'min:1']],
                'execute' => fn (User $actor, array $data): array => ['history' => $this->container->make(HouseholdTaskService::class)->history($actor, (int) $data['task_id'])],
            ],
            'list_visible_analytics_metrics' => [
                'module' => 'analytics', 'scopes' => ['analytics:view'], 'title' => 'List visible analytics metrics',
                'description' => 'List only metrics enabled by the member’s current source module permissions.',
                'schema' => ['type' => 'object', 'properties' => new \stdClass, 'additionalProperties' => false], 'rules' => [],
                'execute' => fn (User $actor): array => ['metrics' => $this->container->make(AnalyticsService::class)->catalog($actor)],
            ],
        ];
    }

    /** @return list<array{name: string, module: string, scopes: list<string>, title: string, description: string, inputSchema: array<string, mixed>}> */
    public function all(): array
    {
        return array_map(fn (string $name, array $read): array => [
            'name' => $name,
            'module' => $read['module'],
            'scopes' => $read['scopes'],
            'title' => $read['title'],
            'description' => $read['description'],
            'inputSchema' => $read['schema'],
        ], array_keys($this->reads), array_values($this->reads));
    }

    /** @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function execute(string $name, User $actor, array $arguments): array
    {
        $read = $this->reads[$name] ?? throw new \InvalidArgumentException('Unknown MCP read workflow.');
        if (array_diff(array_keys($arguments), array_keys((array) $read['schema']['properties'])) !== []) {
            throw ValidationException::withMessages(['arguments' => 'Unexpected MCP read arguments.']);
        }

        $validated = Validator::make($arguments, $read['rules'])->validate();

        return ($read['execute'])($actor, $validated);
    }
}
