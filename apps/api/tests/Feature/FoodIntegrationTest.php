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

    public function test_meal_suggestions_preview_quantities_require_consent_and_import_once(): void
    {
        $admin = $this->member('Suggestion Admin', true);
        $owner = $this->member('Suggestion Owner');
        foreach (['shopping', 'meal_planning', 'recipes'] as $module) {
            $this->grant($admin, $module, ['view' => true, 'manage' => true]);
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

        $previewResponse = $this->preview($owner, $listId)->assertOk();
        $preview = $previewResponse->json('preview');
        $this->assertSame(1, $preview['list_version']);
        $this->assertTrue($preview['availability']['meal_planning']);
        $this->assertTrue($preview['availability']['recipes']);
        $this->assertSame(1, $preview['skipped']['unsupported_quantities']);
        $this->assertCount(2, $preview['suggestions']);
        $this->assertSame(['Flour', 'Potatoes'], array_column($preview['suggestions'], 'name'));
        $this->assertSame('1.000', $preview['suggestions'][0]['quantity']);
        $this->assertSame('2.000', $preview['suggestions'][1]['quantity']);
        $this->assertDatabaseCount('shopping_items', 0);

        $this->import($owner, $listId, $preview, false)->assertUnprocessable()->assertJsonValidationErrors('confirmed');
        $this->assertDatabaseCount('shopping_items', 0);

        $this->import($owner, $listId, $preview, true)->assertOk()
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.name', 'Flour')
            ->assertJsonPath('items.1.name', 'Potatoes');

        $this->import($owner, $listId, $preview, true)->assertStatus(409);
        $this->assertDatabaseCount('shopping_items', 2);
        $this->assertDatabaseHas('shopping_lists', ['id' => $listId, 'version' => 2]);
        $this->assertDatabaseHas('recipes', ['id' => $recipeId, 'servings' => 2]);
        $this->assertDatabaseHas('meal_plan_slots', ['recipe_id' => $recipeId, 'servings' => 4]);
        $this->assertDatabaseHas('shopping_list_events', ['shopping_list_id' => $listId, 'event_type' => 'item_added']);
    }

    public function test_private_meals_and_recipes_are_not_exposed_through_suggestions(): void
    {
        $admin = $this->member('Privacy Admin', true);
        $owner = $this->member('Private Food Owner');
        $viewer = $this->member('Food Viewer');
        foreach (['shopping', 'meal_planning', 'recipes'] as $module) {
            $this->grant($admin, $module, ['view' => true, 'manage' => true]);
            $this->enable($admin, $module);
            $this->grant($viewer, $module, ['view' => true]);
        }
        $this->grant($viewer, 'shopping', ['view' => true, 'create' => true]);
        $this->grant($owner, 'recipes', ['view' => true, 'create' => true]);
        $this->grant($owner, 'meal_planning', ['view' => true, 'create' => true, 'manage' => true]);

        $listId = $this->shoppingList($admin, 'household');
        $recipeId = $this->recipe($owner, 2, [
            ['name' => 'Secret saffron', 'quantity' => '3', 'unit' => 'g'],
        ], 'Private recipe title');
        $slotId = (int) $this->meal($owner, $recipeId, '2026-10-20', 'dinner', 2)
            ->assertCreated()->json('slot.id');
        $slot = MealPlanSlot::query()->findOrFail($slotId);
        app(ModuleAccessService::class)->grantPrivateResourceAccess($owner, $slot, $viewer, ModuleCapability::View);

        $previewResponse = $this->preview($viewer, $listId)->assertOk();
        $this->assertSame(1, $previewResponse->json('preview.skipped.unavailable_recipes'));
        $this->assertCount(0, $previewResponse->json('preview.suggestions'));
        $this->assertStringNotContainsString('Secret saffron', $previewResponse->getContent());
        $this->assertStringNotContainsString('Private recipe title', $previewResponse->getContent());

        $viewOnly = $this->member('Shopping View Only');
        $this->grant($viewOnly, 'shopping', ['view' => true]);
        $this->actingAs($viewOnly, 'web')->postJson("/api/shopping/lists/{$listId}/meal-suggestions", [
            'week_start' => '2026-10-19',
        ])->assertForbidden();
    }

    private function preview(User $member, int $listId): TestResponse
    {
        return $this->actingAs($member, 'web')->postJson("/api/shopping/lists/{$listId}/meal-suggestions", [
            'week_start' => '2026-10-19',
        ]);
    }

    private function import(User $member, int $listId, array $preview, bool $confirmed): TestResponse
    {
        return $this->actingAs($member, 'web')->postJson("/api/shopping/lists/{$listId}/meal-suggestions/import", [
            'week_start' => $preview['week_start'],
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

    private function meal(User $member, ?int $recipeId, string $plannedFor, string $mealType, int $servings): TestResponse
    {
        return $this->actingAs($member, 'web')->postJson('/api/meal-planning/slots', [
            'planned_for' => $plannedFor,
            'meal_type' => $mealType,
            'dish_type' => $recipeId === null ? 'free_text' : 'recipe',
            'recipe_id' => $recipeId,
            'servings' => $servings,
        ]);
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

    private function enable(User $admin, string $module): void
    {
        app(ModuleManagementService::class)->setEnabled($admin, $module, true);
    }
}
