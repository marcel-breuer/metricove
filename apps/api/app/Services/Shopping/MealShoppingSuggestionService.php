<?php

namespace App\Services\Shopping;

use App\Enums\ModuleCapability;
use App\Exceptions\ModuleDisabled;
use App\Exceptions\ModuleNotFound;
use App\Models\Recipe;
use App\Models\User;
use App\Services\MealPlanning\MealPlanningService;
use App\Services\Modules\ModuleAccessService;
use App\Services\Pantry\PantryService;
use App\Services\Recipes\RecipeService;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\RoundingNecessaryException;
use Brick\Math\RoundingMode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class MealShoppingSuggestionService
{
    public function __construct(
        private readonly ModuleAccessService $access,
        private readonly MealPlanningService $plans,
        private readonly RecipeService $recipes,
        private readonly PantryService $pantry,
        private readonly ShoppingService $shopping,
    ) {}

    /** @param array{week_start: string, subtract_pantry: bool} $data
     * @return array<string, mixed>
     */
    public function preview(User $actor, int $listId, array $data): array
    {
        return $this->buildPreview($actor, $listId, $data['week_start'], (bool) $data['subtract_pantry']);
    }

    /** @param array<string, mixed> $data
     * @return list<array<string, mixed>>
     */
    public function import(User $actor, int $listId, array $data): array
    {
        $expiresAt = (int) $data['preview_expires_at'];

        if ($expiresAt < now()->timestamp) {
            throw new ConflictHttpException('This proposal expired. Review the meal suggestions again.');
        }

        $preview = $this->buildPreview(
            $actor,
            $listId,
            (string) $data['week_start'],
            (bool) $data['subtract_pantry'],
            $expiresAt,
        );

        if ((int) $data['expected_list_version'] !== $preview['list_version']
            || ! hash_equals($preview['preview_token'], (string) $data['preview_token'])) {
            throw new ConflictHttpException('The meal plan, pantry or shopping list changed. Review the suggestions again.');
        }

        $byId = [];
        foreach ($preview['suggestions'] as $suggestion) {
            $byId[$suggestion['id']] = $suggestion;
        }

        $selected = [];
        foreach ($data['suggestion_ids'] as $id) {
            if (! isset($byId[$id])) {
                throw ValidationException::withMessages([
                    'suggestion_ids' => 'Choose suggestions from the current preview.',
                ]);
            }
            $selected[] = $byId[$id];
        }

        return $this->shopping->addSuggestedItems(
            $actor,
            $listId,
            $preview['list_version'],
            array_map(fn (array $item): array => [
                'name' => $item['name'],
                'quantity' => $item['quantity'],
                'unit' => $item['unit'],
            ], $selected),
        );
    }

    /** @return array<string, mixed> */
    private function buildPreview(
        User $actor,
        int $listId,
        string $weekStart,
        bool $subtractPantry,
        ?int $expiresAt = null,
    ): array {
        $listVersion = $this->shopping->mealSuggestionListVersion($actor, $listId);
        $expiresAt ??= now()->addMinutes(10)->timestamp;

        $availability = [
            'meal_planning' => false,
            'recipes' => $this->canViewModule($actor, 'recipes'),
            'pantry' => $this->canViewModule($actor, 'pantry'),
            'pantry_subtracted' => false,
        ];
        $week = null;

        try {
            $week = $this->plans->week($actor, $weekStart);
            $availability['meal_planning'] = true;
        } catch (AuthorizationException|ModuleDisabled|ModuleNotFound) {
            // A missing or inaccessible optional source produces an empty preview.
        }

        $pantryItems = [];
        if ($subtractPantry && $availability['pantry']) {
            try {
                $pantryItems = $this->pantry->index($actor);
                $availability['pantry_subtracted'] = true;
            } catch (AuthorizationException|ModuleDisabled|ModuleNotFound) {
                $availability['pantry'] = false;
            }
        }

        $skipped = [
            'free_text_meals' => 0,
            'unavailable_recipes' => 0,
            'unsupported_quantities' => 0,
            'covered_by_pantry' => 0,
        ];
        $groups = [];

        foreach ($week['slots'] ?? [] as $slot) {
            if ($slot['dish_type'] !== 'recipe') {
                $skipped['free_text_meals']++;

                continue;
            }

            $recipeId = $slot['recipe']['id'] ?? null;
            if (! is_int($recipeId) || ! $availability['recipes']) {
                $skipped['unavailable_recipes']++;

                continue;
            }

            try {
                $recipe = $this->recipes->findForMealPlan($actor, $recipeId)->load('ingredients');
            } catch (AuthorizationException|ModelNotFoundException|ModuleDisabled|ModuleNotFound) {
                $skipped['unavailable_recipes']++;

                continue;
            }

            if (! $recipe instanceof Recipe || $recipe->servings < 1) {
                $skipped['unavailable_recipes']++;

                continue;
            }

            foreach ($recipe->ingredients as $ingredient) {
                try {
                    $quantity = BigDecimal::of((string) $ingredient->quantity)
                        ->multipliedBy((int) $slot['servings'])
                        ->dividedBy($recipe->servings, 3, RoundingMode::Unnecessary);
                } catch (RoundingNecessaryException) {
                    $skipped['unsupported_quantities']++;

                    continue;
                }

                $name = trim((string) $ingredient->name);
                $unit = trim((string) $ingredient->unit);
                $nameKey = $this->normalize($name);
                $unitKey = $this->normalize($unit);
                $key = hash('sha256', json_encode([$nameKey, $unitKey], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

                if (! isset($groups[$key])) {
                    $groups[$key] = [
                        'id' => $key,
                        'name' => $name,
                        'unit' => $unit,
                        'name_key' => $nameKey,
                        'unit_key' => $unitKey,
                        'quantity' => BigDecimal::of('0.000'),
                        'source_count' => 0,
                    ];
                }

                $groups[$key]['quantity'] = $groups[$key]['quantity']->plus($quantity);
                $groups[$key]['source_count']++;
            }
        }

        $suggestions = [];
        foreach ($groups as $group) {
            $quantity = $group['quantity'];
            $pantryUsed = BigDecimal::of('0.000');
            $pantryStatus = 'not_requested';

            if ($subtractPantry) {
                $pantryStatus = $availability['pantry_subtracted'] ? 'none' : 'unavailable';
                $hasIncompatibleUnit = false;

                foreach ($pantryItems as $stock) {
                    if ($this->normalize((string) $stock['name']) !== $group['name_key']) {
                        continue;
                    }

                    if ($this->normalize((string) $stock['unit']) !== $group['unit_key']) {
                        $hasIncompatibleUnit = true;

                        continue;
                    }

                    $available = BigDecimal::of((string) $stock['quantity']);
                    $usedHere = BigDecimal::min($available, $quantity);
                    $quantity = $quantity->minus($usedHere);
                    $pantryUsed = $pantryUsed->plus($usedHere);
                    if (! $usedHere->isZero()) {
                        $pantryStatus = 'partially_covered';
                    }

                    if ($quantity->isZero()) {
                        break;
                    }
                }

                if ($quantity->isZero()) {
                    $skipped['covered_by_pantry']++;

                    continue;
                }

                if ($pantryUsed->isZero() && $hasIncompatibleUnit) {
                    $pantryStatus = 'unit_mismatch';
                }
            }

            $quantityText = $quantity->toScale(3)->__toString();
            try {
                $normalized = ShoppingQuantity::normalize($quantityText, $group['unit']);
            } catch (ValidationException) {
                $skipped['unsupported_quantities']++;

                continue;
            }

            $suggestions[] = [
                'id' => $group['id'],
                'name' => $group['name'],
                'quantity' => $normalized['quantity'],
                'unit' => $normalized['unit'],
                'source_count' => $group['source_count'],
                'pantry_quantity_used' => $pantryUsed->toScale(3)->__toString(),
                'pantry_status' => $pantryStatus,
            ];
        }

        usort($suggestions, fn (array $left, array $right): int => strcasecmp($left['name'], $right['name']) ?: strcasecmp($left['unit'], $right['unit']));

        $signature = hash_hmac('sha256', json_encode([
            'actor_id' => $actor->id,
            'list_id' => $listId,
            'list_version' => $listVersion,
            'week_start' => $weekStart,
            'subtract_pantry' => $subtractPantry,
            'availability' => $availability,
            'skipped' => $skipped,
            'suggestions' => $suggestions,
            'expires_at' => $expiresAt,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), (string) config('app.key'));

        return [
            'week_start' => $weekStart,
            'subtract_pantry' => $subtractPantry,
            'list_version' => $listVersion,
            'preview_expires_at' => $expiresAt,
            'preview_token' => $signature,
            'availability' => $availability,
            'skipped' => $skipped,
            'suggestions' => $suggestions,
        ];
    }

    private function canViewModule(User $actor, string $module): bool
    {
        try {
            $this->access->authorize($actor, $module, ModuleCapability::View);

            return true;
        } catch (AuthorizationException|ModuleDisabled|ModuleNotFound) {
            return false;
        }
    }

    private function normalize(string $value): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);

        return mb_strtolower($collapsed, 'UTF-8');
    }
}
