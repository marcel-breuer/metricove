<?php

namespace App\Services\MealPlanning;

use App\Enums\ModuleCapability;
use App\Exceptions\ModuleDisabled;
use App\Exceptions\ModuleNotFound;
use App\Models\HouseholdSettings;
use App\Models\MealPlanSlot;
use App\Models\Recipe;
use App\Models\User;
use App\Services\Modules\ModuleAccessService;
use App\Services\Recipes\RecipeService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MealPlanningService
{
    private const MODULE = 'meal_planning';

    public function __construct(
        private readonly ModuleAccessService $access,
        private readonly RecipeService $recipes,
    ) {}

    /** @return array{week_start: string, week_end: string, slots: list<array<string, mixed>>} */
    public function week(User $actor, string $weekStart): array
    {
        $this->access->authorize($actor, self::MODULE, ModuleCapability::View);
        $start = $this->monday($weekStart);
        $end = $start->addDays(6);
        $slots = $this->visibleSlots($actor, [ModuleCapability::View], MealPlanSlot::query())
            ->whereBetween('planned_for', [$start->toDateString(), $end->toDateString()])
            ->orderBy('planned_for')->orderByRaw("CASE meal_type WHEN 'breakfast' THEN 1 WHEN 'lunch' THEN 2 ELSE 3 END")
            ->orderBy('id')->get();

        return [
            'week_start' => $start->toDateString(),
            'week_end' => $end->toDateString(),
            'slots' => $slots->map(fn (MealPlanSlot $slot): array => $this->present($actor, $slot))->all(),
        ];
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(User $actor, array $data): array
    {
        $private = $data['visibility'] === 'private';
        $capability = $private ? ModuleCapability::Create : ModuleCapability::Manage;
        $this->access->authorize($actor, self::MODULE, $capability);

        try {
            $slot = DB::transaction(function () use ($actor, $data, $private, $capability): MealPlanSlot {
                $this->lockHousehold();

                if ($private) {
                    $this->access->authorize($actor, self::MODULE, ModuleCapability::Create);
                } else {
                    $this->authorizeSharedManagement($actor);
                }

                $recipe = $data['dish_type'] === 'recipe'
                    ? $this->resolveRecipe($actor, (int) $data['recipe_id'])
                    : null;
                $slot = MealPlanSlot::query()->create([
                    'owner_user_id' => $private ? $actor->id : null,
                    'created_by_user_id' => $actor->id,
                    'recipe_id' => $recipe?->id,
                    'is_private' => $private,
                    'scope_key' => $private ? 'member:'.$actor->id : 'household',
                    'planned_for' => $data['planned_for'],
                    'meal_type' => $data['meal_type'],
                    'dish_type' => $data['dish_type'],
                    'dish_name' => $data['dish_type'] === 'free_text' ? trim($data['dish_name']) : null,
                    'servings' => (int) $data['servings'],
                    'notes' => $this->nullableTrim($data['notes'] ?? null),
                ]);
                $this->access->authorize($actor, self::MODULE, $capability, $slot);

                return $slot;
            }, 3);
        } catch (QueryException $exception) {
            $this->rethrowUnlessSlotConflict($exception);
        }

        return $this->present($actor, $slot);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(User $actor, int $slotId, array $data): array
    {
        try {
            $slot = DB::transaction(function () use ($actor, $slotId, $data): MealPlanSlot {
                $this->lockHousehold();
                $slot = $this->findForMutation($actor, $slotId);
                $capability = $slot->isPrivate() ? ModuleCapability::Edit : ModuleCapability::Manage;

                if (! $slot->isPrivate()) {
                    $this->authorizeSharedManagement($actor);
                }

                $this->access->authorize($actor, self::MODULE, $capability, $slot);
                $dishType = $data['dish_type'] ?? $slot->dish_type;
                $recipeId = array_key_exists('recipe_id', $data) ? $data['recipe_id'] : $slot->recipe_id;
                $dishWasSubmitted = array_key_exists('dish_type', $data)
                    || array_key_exists('recipe_id', $data)
                    || array_key_exists('dish_name', $data);

                if ($dishWasSubmitted && $dishType === 'recipe') {
                    if (array_key_exists('dish_name', $data)) {
                        throw ValidationException::withMessages(['dish_name' => 'A linked recipe supplies its own title.']);
                    }

                    if ($recipeId === null) {
                        throw ValidationException::withMessages(['recipe_id' => 'Choose a recipe that you can access.']);
                    }

                    $linkWasSubmitted = array_key_exists('recipe_id', $data)
                        || $dishType !== $slot->dish_type
                        || (int) $recipeId !== (int) $slot->recipe_id;
                    if ($linkWasSubmitted) {
                        $recipe = $this->resolveRecipe($actor, (int) $recipeId);
                        $slot->recipe_id = $recipe->id;
                    }
                    $slot->dish_type = 'recipe';
                    $slot->dish_name = null;
                } elseif ($dishWasSubmitted) {
                    $dishName = $data['dish_name'] ?? ($slot->dish_type === 'free_text' ? $slot->dish_name : null);
                    if (! is_string($dishName) || trim($dishName) === '') {
                        throw ValidationException::withMessages(['dish_name' => 'Enter a dish name.']);
                    }
                    $slot->recipe_id = null;
                    $slot->dish_name = trim($dishName);
                }

                foreach (['planned_for', 'meal_type', 'servings'] as $field) {
                    if (array_key_exists($field, $data)) {
                        $slot->{$field} = $data[$field];
                    }
                }
                if (array_key_exists('notes', $data)) {
                    $slot->notes = $this->nullableTrim($data['notes']);
                }
                $slot->save();

                return $slot->fresh();
            }, 3);
        } catch (QueryException $exception) {
            $this->rethrowUnlessSlotConflict($exception);
        }

        return $this->present($actor, $slot);
    }

    public function delete(User $actor, int $slotId): void
    {
        DB::transaction(function () use ($actor, $slotId): void {
            $this->lockHousehold();
            $slot = $this->findForMutation($actor, $slotId);
            $capability = $slot->isPrivate() ? ModuleCapability::Edit : ModuleCapability::Manage;

            if (! $slot->isPrivate()) {
                $this->authorizeSharedManagement($actor);
            }

            $this->access->authorize($actor, self::MODULE, $capability, $slot);
            $slot->delete();
        }, 3);
    }

    private function monday(string $weekStart): CarbonImmutable
    {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $weekStart);

        if (! $date || $date->format('Y-m-d') !== $weekStart || $date->dayOfWeekIso !== 1) {
            throw ValidationException::withMessages(['week_start' => 'Choose the Monday that starts the week.']);
        }

        return $date;
    }

    private function findForMutation(User $actor, int $slotId): MealPlanSlot
    {
        return $this->visibleSlots($actor, [ModuleCapability::Edit, ModuleCapability::Manage], MealPlanSlot::query())
            ->whereKey($slotId)->lockForUpdate()->firstOrFail();
    }

    /** @param list<ModuleCapability> $capabilities */
    private function visibleSlots(User $actor, array $capabilities, Builder $query): Builder
    {
        $morphType = (new MealPlanSlot)->getMorphClass();
        $values = array_map(fn (ModuleCapability $capability): string => $capability->value, $capabilities);

        return $query->where(function (Builder $visible) use ($actor, $morphType, $values): void {
            $visible->where('is_private', false)
                ->orWhere('owner_user_id', $actor->id)
                ->orWhereExists(function (QueryBuilder $permissions) use ($actor, $morphType, $values): void {
                    $permissions->selectRaw('1')->from('resource_user_permissions')
                        ->whereColumn('resource_user_permissions.resource_id', 'meal_plan_slots.id')
                        ->where('resource_user_permissions.resource_type', $morphType)
                        ->where('resource_user_permissions.user_id', $actor->id)
                        ->whereIn('resource_user_permissions.capability', $values);
                });
        });
    }

    /** @return array<string, mixed> */
    private function present(User $actor, MealPlanSlot $slot): array
    {
        $recipe = null;
        $dishLabel = $slot->dish_name;

        if ($slot->dish_type === 'recipe') {
            $dishLabel = 'Linked recipe';
            if ($slot->recipe_id === null) {
                $dishLabel = 'Recipe no longer available';
            } else {
                try {
                    $linkedRecipe = $this->recipes->findForMealPlan($actor, (int) $slot->recipe_id);
                    $recipe = ['id' => $linkedRecipe->id, 'title' => $linkedRecipe->title];
                    $dishLabel = $linkedRecipe->title;
                } catch (AuthorizationException|ModelNotFoundException|ModuleDisabled|ModuleNotFound) {
                    // A meal slot may be visible when the separately protected recipe is not.
                }
            }
        }

        return [
            'id' => $slot->id,
            'planned_for' => $slot->planned_for->format('Y-m-d'),
            'meal_type' => $slot->meal_type,
            'dish_type' => $slot->dish_type,
            'dish_name' => $slot->dish_name,
            'dish_label' => $dishLabel,
            'recipe' => $recipe,
            'servings' => $slot->servings,
            'notes' => $slot->notes,
            'visibility' => $slot->isPrivate() ? 'private' : 'household',
            'is_private' => $slot->isPrivate(),
            'can_edit' => $this->canEdit($actor, $slot),
        ];
    }

    private function canEdit(User $actor, MealPlanSlot $slot): bool
    {
        try {
            if ($slot->isPrivate()) {
                $this->access->authorize($actor, self::MODULE, ModuleCapability::Edit, $slot);

                return true;
            }

            $this->access->authorize($actor, self::MODULE, ModuleCapability::Manage);

            return User::query()->whereKey($actor->id)->where('is_admin', true)->where('is_active', true)->exists();
        } catch (AuthorizationException|ModuleDisabled|ModuleNotFound) {
            return false;
        }
    }

    private function authorizeSharedManagement(User $actor): void
    {
        $admin = User::query()->whereKey($actor->id)->where('is_admin', true)->where('is_active', true)->lockForUpdate()->first();

        if (! $admin instanceof User) {
            throw new AuthorizationException('Only an active household administrator can manage a household meal plan.');
        }

        $this->access->authorize($admin, self::MODULE, ModuleCapability::Manage);
    }

    private function resolveRecipe(User $actor, int $recipeId): Recipe
    {
        try {
            return $this->recipes->findForMealPlan($actor, $recipeId);
        } catch (ModuleDisabled|ModuleNotFound) {
            throw ValidationException::withMessages([
                'recipe_id' => 'Recipes must be enabled before a recipe can be linked.',
            ]);
        }
    }

    private function lockHousehold(): void
    {
        HouseholdSettings::query()->whereKey(1)->lockForUpdate()->firstOrFail();
    }

    private function nullableTrim(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function rethrowUnlessSlotConflict(QueryException $exception): never
    {
        if (str_contains($exception->getMessage(), 'meal_plan_scope_date_meal_unique')
            || str_contains($exception->getMessage(), 'meal_plan_slots.scope_key, meal_plan_slots.planned_for, meal_plan_slots.meal_type')) {
            throw ValidationException::withMessages([
                'meal_type' => 'A meal is already planned for this slot and day.',
            ]);
        }

        throw $exception;
    }
}
