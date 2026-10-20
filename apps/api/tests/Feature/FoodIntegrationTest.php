<?php

namespace Tests\Feature;

use App\Enums\ModuleCapability;
use App\Models\HouseholdSettings;
use App\Models\MealPlanSlot;
use App\Models\ModuleUserPermission;
use App\Models\User;
use App\Services\Modules\ModuleAccessService;
use App\Services\Modules\ModuleManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class FoodIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private int $memberSequence = 0;

    public function test_meal_suggestions_preview_exact_quantities_require_consent_and_never_change_pantry(): void
    {
        $admin = $this->member('Suggestion Admin', true);
        $owner = $this->member('Suggestion Owner');
        $this->grant($admin, 'shopping', ['view' => true, 'manage' => true]);
        foreach (['meal_planning', 'recipes', 'pantry'] as $module) {
            $this->grant($admin, $module, ['view' => true, 'manage' => true]);
        }
        foreach (['shopping', 'meal_planning', 'recipes', 'pantry'] as $module) {
            $this->grant($owner, $module, ['view' => true, 'create' => true, 'edit' => true]);
            $this->enable($admin, $module);
        }

        $listId = $this->shoppingList($owner);
        $recipeId = $this->recipe($owner, 2, [
            ['name' => 'Potatoes', 'quantity' => '1', 'unit' => 'kg'],
            ['name' => 'Flour', 'quantity' => '0.5', 'unit' => 'kg'],
            ['name' => 'Cinnamon', 'quantity' => '0.0001', 'unit' => 'tsp'],
        ]);
        $this->meal($owner, $recipeId, '2026-10-20', 'dinner', 4)->assertCreated();
        $potatoesId = $this->pantryItem($owner, 'Potatoes', '0.500', 'kg');
        $this->pantryItem($owner, 'Flour', '250', 'g');

        $previewResponse = $this->preview($owner, $listId, true)->assertOk();
        $preview = $previewResponse->json('preview');
        $this->assertSame(1, $preview['list_version']);
        $this->assertTrue($preview['availability']['meal_planning']);
        $this->assertTrue($preview['availability']['recipes']);
        $this->assertTrue($preview['availability']['pantry_subtracted']);
        $this->assertSame(1, $preview['skipped']['unsupported_quantities']);
        $this->assertCount(2, $preview['suggestions']);
        $this->assertSame(['Flour', 'Potatoes'], array_column($preview['suggestions'], 'name'));
        $this->assertSame('1.000', $preview['suggestions'][0]['quantity']);
        $this->assertSame('unit_mismatch', $preview['suggestions'][0]['pantry_status']);
        $this->assertSame('1.500', $preview['suggestions'][1]['quantity']);
        $this->assertSame('0.500', $preview['suggestions'][1]['pantry_quantity_used']);
        $this->assertDatabaseCount('shopping_items', 0);
        $this->assertDatabaseHas('pantry_items', ['id' => $potatoesId, 'quantity' => '0.500']);
        $this->assertDatabaseCount('pantry_stock_movements', 2);

        $this->actingAs($owner, 'web')->postJson("/api/pantry/{$potatoesId}/adjustments", [
            'expected_version' => 1, 'quantity_delta' => '0.250',
        ])->assertOk();
        $this->import($owner, $listId, $preview, false)->assertUnprocessable()->assertJsonValidationErrors('confirmed');
        $this->assertDatabaseCount('shopping_items', 0);

        $freshResponse = $this->preview($owner, $listId, true)->assertOk();
        $fresh = $freshResponse->json('preview');
        $this->assertSame('1.250', $fresh['suggestions'][1]['quantity']);
        $this->import($owner, $listId, $fresh, true)->assertOk()
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.name', 'Flour')
            ->assertJsonPath('items.1.name', 'Potatoes');

        $this->import($owner, $listId, $fresh, true)->assertStatus(409);
        $this->assertDatabaseCount('shopping_items', 2);
        $this->assertDatabaseHas('shopping_lists', ['id' => $listId, 'version' => 2]);
        $this->assertDatabaseHas('pantry_items', ['id' => $potatoesId, 'quantity' => '0.750']);
        $this->assertDatabaseCount('pantry_stock_movements', 3);
        $this->assertDatabaseHas('recipes', ['id' => $recipeId, 'servings' => 2]);
        $this->assertDatabaseHas('meal_plan_slots', ['recipe_id' => $recipeId, 'servings' => 4]);
        $this->assertDatabaseHas('shopping_list_events', ['shopping_list_id' => $listId, 'event_type' => 'item_added']);
    }

    public function test_private_meals_recipes_and_pantry_are_not_exposed_through_suggestions(): void
    {
        $admin = $this->member('Privacy Admin', true);
        $owner = $this->member('Private Food Owner');
        $viewer = $this->member('Food Viewer');
        foreach (['shopping', 'meal_planning', 'recipes', 'pantry'] as $module) {
            $this->grant($admin, $module, ['view' => true, 'manage' => true]);
            $this->enable($admin, $module);
            $this->grant($viewer, $module, ['view' => true]);
        }
        $this->grant($viewer, 'shopping', ['view' => true, 'create' => true]);
        $this->grant($owner, 'recipes', ['view' => true, 'create' => true]);
        $this->grant($owner, 'meal_planning', ['view' => true, 'create' => true, 'manage' => true]);
        $this->grant($owner, 'pantry', ['view' => true, 'create' => true]);

        $listId = $this->shoppingList($admin, 'household');
        $recipeId = $this->recipe($owner, 2, [
            ['name' => 'Secret saffron', 'quantity' => '3', 'unit' => 'g'],
        ], 'Private recipe title');
        $slotId = (int) $this->meal($owner, $recipeId, '2026-10-20', 'dinner', 2)
            ->assertCreated()->json('slot.id');
        $slot = MealPlanSlot::query()->findOrFail($slotId);
        app(ModuleAccessService::class)->grantPrivateResourceAccess($owner, $slot, $viewer, ModuleCapability::View);
        $pantryId = $this->pantryItem($owner, 'Private saffron', '100', 'g');

        $previewResponse = $this->preview($viewer, $listId, true)->assertOk();
        $this->assertSame(1, $previewResponse->json('preview.skipped.unavailable_recipes'));
        $this->assertCount(0, $previewResponse->json('preview.suggestions'));
        $this->assertStringNotContainsString('Secret saffron', $previewResponse->getContent());
        $this->assertStringNotContainsString('Private recipe title', $previewResponse->getContent());
        $this->assertStringNotContainsString('Private saffron', $previewResponse->getContent());
        $this->assertDatabaseHas('pantry_items', ['id' => $pantryId, 'quantity' => '100.000']);

        $viewOnly = $this->member('Shopping View Only');
        $this->grant($viewOnly, 'shopping', ['view' => true]);
        $this->actingAs($viewOnly, 'web')->postJson("/api/shopping/lists/{$listId}/meal-suggestions", [
            'week_start' => '2026-10-19', 'subtract_pantry' => false,
        ])->assertForbidden();
    }

    public function test_disabled_food_sources_degrade_and_reactivation_restores_authorized_suggestions(): void
    {
        $admin = $this->member('Reactivation Admin', true);
        foreach (['shopping', 'meal_planning', 'recipes', 'pantry'] as $module) {
            $this->grant($admin, $module, ['view' => true, 'create' => true, 'edit' => true, 'manage' => true]);
            $this->enable($admin, $module);
        }

        $listId = $this->shoppingList($admin);
        $recipeId = $this->recipe($admin, 2, [
            ['name' => 'Brown rice', 'quantity' => '1', 'unit' => 'kg'],
        ]);
        $slotId = (int) $this->meal($admin, $recipeId, '2026-10-20', 'dinner', 2)->assertCreated()->json('slot.id');
        $this->meal($admin, null, '2026-10-19', 'breakfast', 1, 'Toast')->assertCreated();
        $pantryId = $this->pantryItem($admin, 'Brown rice', '0.250', 'kg');

        $this->enable($admin, 'recipes', false);
        $this->enable($admin, 'pantry', false);
        $disabledSources = $this->preview($admin, $listId, true)->assertOk()->json('preview');
        $this->assertTrue($disabledSources['availability']['meal_planning']);
        $this->assertFalse($disabledSources['availability']['recipes']);
        $this->assertFalse($disabledSources['availability']['pantry']);
        $this->assertSame(1, $disabledSources['skipped']['free_text_meals']);
        $this->assertSame(1, $disabledSources['skipped']['unavailable_recipes']);
        $this->assertCount(0, $disabledSources['suggestions']);

        $this->enable($admin, 'meal_planning', false);
        $unavailablePlans = $this->preview($admin, $listId, true)->assertOk()->json('preview');
        $this->assertFalse($unavailablePlans['availability']['meal_planning']);
        $this->assertCount(0, $unavailablePlans['suggestions']);
        $this->assertDatabaseHas('recipes', ['id' => $recipeId, 'title' => 'Lemon pasta']);
        $this->assertDatabaseHas('meal_plan_slots', ['id' => $slotId, 'recipe_id' => $recipeId]);
        $this->assertDatabaseHas('pantry_items', ['id' => $pantryId, 'quantity' => '0.250']);

        $this->enable($admin, 'recipes');
        $this->enable($admin, 'pantry');
        $this->enable($admin, 'meal_planning');
        $restored = $this->preview($admin, $listId, true)->assertOk()->json('preview');
        $this->assertTrue($restored['availability']['meal_planning']);
        $this->assertTrue($restored['availability']['pantry_subtracted']);
        $this->assertSame(1, $restored['skipped']['free_text_meals']);
        $this->assertCount(1, $restored['suggestions']);
        $this->assertSame('0.750', $restored['suggestions'][0]['quantity']);
        $this->assertSame('0.250', $restored['suggestions'][0]['pantry_quantity_used']);
        $this->assertDatabaseHas('pantry_items', ['id' => $pantryId, 'quantity' => '0.250']);
    }

    private function preview(User $member, int $listId, bool $subtractPantry): TestResponse
    {
        return $this->actingAs($member, 'web')->postJson("/api/shopping/lists/{$listId}/meal-suggestions", [
            'week_start' => '2026-10-19',
            'subtract_pantry' => $subtractPantry,
        ]);
    }

    private function import(User $member, int $listId, array $preview, bool $confirmed): TestResponse
    {
        return $this->actingAs($member, 'web')->postJson("/api/shopping/lists/{$listId}/meal-suggestions/import", [
            'week_start' => $preview['week_start'],
            'subtract_pantry' => $preview['subtract_pantry'],
            'expected_list_version' => $preview['list_version'],
            'preview_expires_at' => $preview['preview_expires_at'],
            'preview_token' => $preview['preview_token'],
            'confirmed' => $confirmed,
            'suggestion_ids' => array_column($preview['suggestions'], 'id'),
        ]);
    }

    /** @param list<array{name: string, quantity: string, unit: string}> $ingredients */
    private function recipe(User $member, int $servings, array $ingredients, string $title = 'Lemon pasta'): int
    {
        return (int) $this->actingAs($member, 'web')->postJson('/api/recipes', [
            'title' => $title,
            'instructions' => 'Prepare the ingredients.',
            'servings' => $servings,
            'ingredients' => $ingredients,
        ])->assertCreated()->json('recipe.id');
    }

    private function meal(
        User $member,
        ?int $recipeId,
        string $plannedFor,
        string $mealType,
        int $servings,
        ?string $dishName = null,
    ): TestResponse {
        return $this->actingAs($member, 'web')->postJson('/api/meal-planning/slots', [
            'planned_for' => $plannedFor,
            'meal_type' => $mealType,
            'dish_type' => $recipeId === null ? 'free_text' : 'recipe',
            'dish_name' => $dishName,
            'recipe_id' => $recipeId,
            'servings' => $servings,
        ]);
    }

    private function pantryItem(User $member, string $name, string $quantity, string $unit): int
    {
        return (int) $this->actingAs($member, 'web')->postJson('/api/pantry', [
            'name' => $name,
            'quantity' => $quantity,
            'unit' => $unit,
        ])->assertCreated()->json('item.id');
    }

    private function shoppingList(User $member, string $visibility = 'private'): int
    {
        return (int) $this->actingAs($member, 'web')->postJson('/api/shopping/lists', [
            'name' => 'Meal suggestions',
            'visibility' => $visibility,
        ])->assertCreated()->json('list.id');
    }

    private function member(string $name, bool $isAdmin = false): User
    {
        HouseholdSettings::query()->whereKey(1)->update(['setup_completed_at' => now()]);
        $this->memberSequence++;

        return User::factory()->create([
            'name' => $name,
            'email' => 'food-'.$this->memberSequence.'@example.test',
            'is_admin' => $isAdmin,
            'is_active' => true,
        ]);
    }

    /** @param array{view?: bool, create?: bool, edit?: bool, manage?: bool} $capabilities */
    private function grant(User $member, string $module, array $capabilities): void
    {
        ModuleUserPermission::query()->updateOrCreate(
            ['user_id' => $member->id, 'module_key' => $module],
            [
                'can_view' => $capabilities['view'] ?? false,
                'can_create' => $capabilities['create'] ?? false,
                'can_edit' => $capabilities['edit'] ?? false,
                'can_manage' => $capabilities['manage'] ?? false,
            ],
        );
    }

    private function enable(User $admin, string $module, bool $enabled = true): void
    {
        app(ModuleManagementService::class)->setEnabled($admin, $module, $enabled);
    }
}
