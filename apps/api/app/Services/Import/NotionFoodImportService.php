<?php

namespace App\Services\Import;

use App\Enums\ModuleCapability;
use App\Exceptions\ModuleDisabled;
use App\Models\HouseholdSettings;
use App\Models\MealPlanSlot;
use App\Models\Recipe;
use App\Models\User;
use App\Services\MealPlanning\MealPlanningService;
use App\Services\Modules\ModuleAccessService;
use App\Services\Recipes\RecipeQuantity;
use App\Services\Recipes\RecipeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class NotionFoodImportService
{
    public function __construct(
        private readonly ModuleAccessService $access,
        private readonly RecipeService $recipes,
        private readonly MealPlanningService $mealPlanning,
    ) {}

    /** @param array<string, mixed> $data
     * @return array{items: list<array<string, mixed>>, ready_count: int, duplicate_count: int, review_count: int}
     */
    public function preview(User $actor, array $data): array
    {
        $items = [];
        $seen = [];

        foreach ($data['recipes'] ?? [] as $recipe) {
            $items[] = $this->previewRecipe($actor, $recipe, $seen);
        }

        foreach ($data['meal_plans'] ?? [] as $plan) {
            $items[] = $this->previewPlan($actor, $plan, $seen);
        }

        return [
            'items' => $items,
            'ready_count' => count(array_filter($items, fn (array $item): bool => $item['ready'] && ! $item['duplicate'])),
            'duplicate_count' => count(array_filter($items, fn (array $item): bool => $item['duplicate'])),
            'review_count' => count(array_filter($items, fn (array $item): bool => ! $item['ready'] && ! $item['duplicate'])),
        ];
    }

    /** @param array<string, mixed> $data
     * @return array{imported_count: int, duplicate_count: int}
     */
    public function import(User $actor, array $data): array
    {
        $selected = array_values(array_unique($data['selected_source_ids'] ?? []));
        if ($selected === []) {
            throw ValidationException::withMessages(['selected_source_ids' => 'Select at least one item to import.']);
        }

        $preview = $this->preview($actor, $data);
        $bySource = [];
        foreach ($preview['items'] as $item) {
            $bySource[$item['source_id']] = $item;
        }
        foreach ($selected as $sourceId) {
            if (! isset($bySource[$sourceId])) {
                throw ValidationException::withMessages(['selected_source_ids' => 'The selection no longer matches the import preview. Review the file again.']);
            }
            if (! $bySource[$sourceId]['ready'] && ! $bySource[$sourceId]['duplicate']) {
                throw ValidationException::withMessages(['selected_source_ids' => 'Resolve items marked for review before importing them.']);
            }
        }

        $recipeRows = collect($data['recipes'] ?? [])->keyBy('source_id');
        $planRows = collect($data['meal_plans'] ?? [])->keyBy('source_id');

        return DB::transaction(function () use ($actor, $selected, $bySource, $recipeRows, $planRows): array {
            HouseholdSettings::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            $imported = 0;
            $duplicates = 0;

            foreach ($selected as $sourceId) {
                $item = $bySource[$sourceId];
                if ($item['duplicate']) {
                    $duplicates++;

                    continue;
                }

                if ($item['type'] === 'recipe') {
                    $data = $this->recipeData($recipeRows->get($sourceId));
                    $recipe = $this->recipes->create($actor, $data);
                    Recipe::query()->whereKey($recipe['id'])->update(['source_system' => 'notion', 'source_id' => $sourceId]);
                } else {
                    $plan = $planRows->get($sourceId);
                    $slot = $this->mealPlanning->create($actor, [
                        'visibility' => 'private',
                        'planned_for' => $plan['planned_for'],
                        'meal_type' => $plan['meal_type'],
                        'dish_type' => 'free_text',
                        'dish_name' => trim($plan['dish_name']),
                        'servings' => (int) ($plan['servings'] ?? 2),
                        'notes' => $plan['notes'] ?? null,
                    ]);
                    MealPlanSlot::query()->whereKey($slot['id'])->update(['source_system' => 'notion', 'source_id' => $sourceId]);
                }
                $imported++;
            }

            return ['imported_count' => $imported, 'duplicate_count' => $duplicates];
        }, 3);
    }

    /** @param array<string, mixed> $recipe
     * @param  array<string, bool>  $seen
     * @return array<string, mixed>
     */
    private function previewRecipe(User $actor, array $recipe, array &$seen): array
    {
        $sourceId = $recipe['source_id'];
        $warnings = [];
        $ready = true;
        $unsupported = array_values(array_diff(array_keys($recipe), ['source_id', 'title', 'category', 'instructions', 'servings', 'preparation_minutes', 'ingredients']));
        if ($unsupported !== []) {
            $ready = false;
            $warnings[] = 'Unsupported recipe fields need review before importing.';
        }
        try {
            $this->access->authorize($actor, 'recipes', ModuleCapability::Create);
        } catch (AuthorizationException|ModuleDisabled) {
            $ready = false;
            $warnings[] = 'Recipes is disabled or this account does not have Recipes Create access.';
        }

        $instructions = $this->text($recipe['instructions'] ?? null);
        $ingredients = $recipe['ingredients'] ?? [];
        $title = trim((string) ($recipe['title'] ?? ''));
        if ($title === '') {
            $ready = false;
            $warnings[] = 'Recipe title is missing and needs review.';
        }
        if ($instructions === '') {
            $ready = false;
            $warnings[] = 'Recipe instructions are missing and need review.';
        }
        if ($ingredients === []) {
            $ready = false;
            $warnings[] = 'Recipe ingredients are missing and need review.';
        }
        if (! isset($recipe['servings'])) {
            $warnings[] = 'Serving count is missing; the import will use two servings.';
        }
        foreach ($ingredients as $ingredient) {
            if (array_diff(array_keys($ingredient), ['name', 'quantity', 'unit', 'note']) !== []) {
                $ready = false;
                $warnings[] = 'Unsupported ingredient fields need review before importing.';
            }
            if (RecipeQuantity::normalize($ingredient['quantity'] ?? null) === null || trim((string) ($ingredient['unit'] ?? '')) === '') {
                $ready = false;
                $warnings[] = 'An ingredient needs a supported quantity and unit.';
            }
            if (trim((string) ($ingredient['name'] ?? '')) === '') {
                $ready = false;
                $warnings[] = 'An ingredient name is missing and needs review.';
            }
        }

        $key = 'recipe:'.$sourceId;
        $duplicate = isset($seen[$key]) || Recipe::query()->where('owner_user_id', $actor->id)
            ->where('source_system', 'notion')->where('source_id', $sourceId)->exists();
        $seen[$key] = true;
        if ($duplicate) {
            $warnings[] = 'This Notion recipe has already been imported.';
        }

        return [
            'type' => 'recipe',
            'source_id' => $sourceId,
            'label' => $title === '' ? 'Untitled recipe' : $title,
            'servings' => $recipe['servings'] ?? null,
            'ingredients' => array_map(static fn (array $ingredient): array => [
                'name' => trim((string) ($ingredient['name'] ?? '')),
                'quantity' => is_scalar($ingredient['quantity'] ?? null) ? (string) $ingredient['quantity'] : '',
                'unit' => trim((string) ($ingredient['unit'] ?? '')),
                'note' => $ingredient['note'] ?? null,
            ], $ingredients),
            'ready' => $ready,
            'duplicate' => $duplicate,
            'warnings' => $warnings,
            'unsupported_fields' => $unsupported,
        ];
    }

    /** @param array<string, mixed> $plan
     * @param  array<string, bool>  $seen
     * @return array<string, mixed>
     */
    private function previewPlan(User $actor, array $plan, array &$seen): array
    {
        $sourceId = $plan['source_id'];
        $warnings = [];
        $ready = true;
        $unsupported = array_values(array_diff(array_keys($plan), ['source_id', 'planned_for', 'meal_type', 'dish_name', 'servings', 'notes']));
        if ($unsupported !== []) {
            $ready = false;
            $warnings[] = 'Unsupported meal plan fields need review before importing.';
        }
        try {
            $this->access->authorize($actor, 'meal_planning', ModuleCapability::Create);
        } catch (AuthorizationException|ModuleDisabled) {
            $ready = false;
            $warnings[] = 'Meal Planning is disabled or this account does not have Meal Planning Create access.';
        }

        $key = 'meal_plan:'.$sourceId;
        $duplicate = isset($seen[$key]) || MealPlanSlot::query()->where('owner_user_id', $actor->id)
            ->where('source_system', 'notion')->where('source_id', $sourceId)->exists();
        $seen[$key] = true;

        $plannedFor = $plan['planned_for'] ?? null;
        $mealType = $plan['meal_type'] ?? null;
        $dishName = trim((string) ($plan['dish_name'] ?? ''));
        $validDate = is_string($plannedFor) && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $plannedFor) === 1
            && checkdate((int) substr($plannedFor, 5, 2), (int) substr($plannedFor, 8, 2), (int) substr($plannedFor, 0, 4));
        $validMealType = is_string($mealType) && in_array($mealType, ['breakfast', 'lunch', 'dinner'], true);
        if (! $validDate) {
            $ready = false;
            $warnings[] = 'A valid meal date is missing and needs review.';
        }
        if (! $validMealType) {
            $ready = false;
            $warnings[] = 'A meal type is missing and needs review.';
        }
        if ($dishName === '') {
            $ready = false;
            $warnings[] = 'The meal name is missing and needs review.';
        }

        $scopeKey = 'member:'.$actor->id;
        $occupied = $validDate && $validMealType && MealPlanSlot::query()->where('scope_key', $scopeKey)
            ->whereDate('planned_for', $plannedFor)->where('meal_type', $mealType)->exists();
        if ($occupied && ! $duplicate) {
            $ready = false;
            $warnings[] = 'This day and meal already has an entry. Review it before importing.';
        }
        if ($duplicate) {
            $warnings[] = 'This Notion meal has already been imported.';
        }
        if (! isset($plan['servings'])) {
            $warnings[] = 'Serving count is missing; the import will use two servings.';
        }

        return [
            'type' => 'meal_plan',
            'source_id' => $sourceId,
            'label' => $dishName === '' ? 'Unnamed meal' : $dishName,
            'planned_for' => $plannedFor,
            'meal_type' => $mealType,
            'servings' => $plan['servings'] ?? null,
            'notes' => $plan['notes'] ?? null,
            'ready' => $ready,
            'duplicate' => $duplicate,
            'warnings' => $warnings,
            'unsupported_fields' => $unsupported,
        ];
    }

    /** @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function recipeData(array $source): array
    {
        $ingredients = array_map(static fn (array $ingredient): array => [
            'name' => trim($ingredient['name']),
            'quantity' => RecipeQuantity::normalize($ingredient['quantity']),
            'unit' => trim($ingredient['unit']),
            'note' => $ingredient['note'] ?? null,
        ], $source['ingredients']);

        return [
            'title' => trim($source['title']),
            'category' => $source['category'] ?? null,
            'instructions' => $this->text($source['instructions']),
            'servings' => (int) ($source['servings'] ?? 2),
            'preparation_minutes' => $source['preparation_minutes'] ?? null,
            'visibility' => 'private',
            'ingredients' => $ingredients,
        ];
    }

    private function text(mixed $value): string
    {
        if (is_array($value)) {
            return trim(implode("\n", array_filter(array_map(static fn (mixed $line): string => is_string($line) ? trim($line) : '', $value))));
        }

        return is_string($value) ? trim($value) : '';
    }
}
