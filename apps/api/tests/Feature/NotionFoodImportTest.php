<?php

namespace Tests\Feature;

use App\Models\HouseholdSettings;
use App\Models\MealPlanSlot;
use App\Models\ModuleUserPermission;
use App\Models\Recipe;
use App\Models\User;
use App\Services\Modules\ModuleManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotionFoodImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_preview_keeps_source_quantities_and_repeated_imports_are_idempotent(): void
    {
        $admin = $this->member('Notion Import Admin', true);
        foreach (['import', 'recipes', 'meal_planning'] as $module) {
            $this->enable($admin, $module);
            $this->grant($admin, $module, ['view' => true, 'create' => true, 'edit' => true]);
        }
        $payload = $this->payload();
        $this->actingAs($admin, 'web')->postJson('/api/import/notion/food/preview', $payload)
            ->assertOk()->assertJsonPath('ready_count', 2)->assertJsonPath('duplicate_count', 0)
            ->assertJsonPath('items.0.label', 'Lemon pasta')->assertJsonPath('items.0.ingredients.0.quantity', '0.1')
            ->assertJsonPath('items.1.planned_for', '2026-05-04')->assertJsonPath('items.1.servings', 4)
            ->assertJsonPath('items.1.notes', 'Use the garden lemons.');
        $selection = array_merge($payload, ['selected_source_ids' => ['recipe-page-1', 'week-page-1']]);
        $this->actingAs($admin, 'web')->postJson('/api/import/notion/food/import', $selection)
            ->assertOk()->assertJsonPath('imported_count', 2)->assertJsonPath('duplicate_count', 0);
        $this->assertDatabaseHas('recipes', ['owner_user_id' => $admin->id, 'source_system' => 'notion', 'source_id' => 'recipe-page-1', 'is_private' => true]);
        $this->assertDatabaseHas('recipe_ingredients', ['name' => 'Sugar', 'quantity' => '0.1000', 'unit' => 'kg']);
        $this->assertDatabaseHas('meal_plan_slots', ['owner_user_id' => $admin->id, 'source_system' => 'notion', 'source_id' => 'week-page-1', 'planned_for' => '2026-05-04']);
        $this->actingAs($admin, 'web')->postJson('/api/import/notion/food/preview', $payload)
            ->assertOk()->assertJsonPath('ready_count', 0)->assertJsonPath('duplicate_count', 2);
        $this->actingAs($admin, 'web')->postJson('/api/import/notion/food/import', $selection)
            ->assertOk()->assertJsonPath('imported_count', 0)->assertJsonPath('duplicate_count', 2);
        $this->assertDatabaseCount('recipes', 1);
        $this->assertDatabaseCount('meal_plan_slots', 1);
    }

    public function test_unavailable_modules_and_incomplete_data_are_reported_without_blocking_valid_items(): void
    {
        $admin = $this->member('Partial Notion Admin', true);
        $this->enable($admin, 'import');
        $this->enable($admin, 'meal_planning');
        $this->grant($admin, 'import', ['view' => true, 'create' => true]);
        $this->grant($admin, 'meal_planning', ['view' => true, 'create' => true]);
        $payload = $this->payload();
        $preview = $this->actingAs($admin, 'web')->postJson('/api/import/notion/food/preview', $payload)
            ->assertOk()->assertJsonPath('items.0.ready', false)->assertJsonPath('items.1.ready', true)
            ->assertJsonPath('ready_count', 1)->assertJsonPath('review_count', 1);
        $this->actingAs($admin, 'web')->postJson('/api/import/notion/food/import', array_merge($payload, ['selected_source_ids' => ['week-page-1']]))
            ->assertOk()->assertJsonPath('imported_count', 1);
        $this->assertDatabaseCount('recipes', 0);
        $this->assertSame('week-page-1', MealPlanSlot::query()->firstOrFail()->source_id);

        $this->enable($admin, 'recipes');
        $this->grant($admin, 'recipes', ['view' => true, 'create' => true]);
        $payload['recipes'][0]['ingredients'][0]['quantity'] = null;
        $needsReview = $this->actingAs($admin, 'web')->postJson('/api/import/notion/food/preview', $payload)
            ->assertOk()->assertJsonPath('items.0.ready', false);
        $this->assertStringContainsString('quantity and unit', implode(' ', $needsReview->json('items.0.warnings')));
        $this->assertDatabaseCount('recipes', 0);

        $incomplete = $this->actingAs($admin, 'web')->postJson('/api/import/notion/food/preview', [
            'recipes' => [['source_id' => 'recipe-page-incomplete']],
            'meal_plans' => [['source_id' => 'week-page-incomplete', 'planned_for' => 'not-a-date', 'meal_type' => 'brunch', 'dish_name' => '']],
        ])->assertOk()->assertJsonPath('items.0.ready', false)->assertJsonPath('items.1.ready', false);
        $this->assertStringContainsString('Recipe title is missing', implode(' ', $incomplete->json('items.0.warnings')));
        $this->assertStringContainsString('valid meal date', implode(' ', $incomplete->json('items.1.warnings')));
    }

    public function test_incomplete_recipe_source_is_imported_unchanged_flagged_and_editable(): void
    {
        $admin = $this->member('Incomplete recipe admin', true);
        $this->enable($admin, 'import');
        $this->enable($admin, 'recipes');
        $this->grant($admin, 'import', ['view' => true, 'create' => true]);
        $this->grant($admin, 'recipes', ['view' => true, 'create' => true, 'edit' => true]);
        $payload = ['recipes' => [[
            'source_id' => 'source-incomplete-1', 'title' => 'Soup from notes', 'instructions' => '', 'servings' => 3,
            'ingredients' => [['name' => 'Salt', 'quantity' => null, 'unit' => null]],
        ]]];

        $this->actingAs($admin, 'web')->postJson('/api/import/notion/food/preview', $payload)
            ->assertOk()->assertJsonPath('items.0.ready', false)->assertJsonPath('review_count', 1);
        $this->actingAs($admin, 'web')->postJson('/api/import/notion/food/import', array_merge($payload, ['selected_source_ids' => ['source-incomplete-1']]))
            ->assertOk()->assertJsonPath('imported_count', 1);
        $recipe = Recipe::query()->where('source_id', 'source-incomplete-1')->firstOrFail();
        $this->assertTrue($recipe->needs_review);
        $this->assertSame('', $recipe->instructions);
        $this->assertDatabaseHas('recipe_ingredients', ['recipe_id' => $recipe->id, 'name' => 'Salt', 'quantity' => null, 'unit' => null]);

        $this->actingAs($admin, 'web')->patchJson('/api/recipes/'.$recipe->id, [
            'instructions' => 'Season to taste.', 'ingredients' => [['name' => 'Salt', 'quantity' => '1', 'unit' => 'pinch']],
        ])->assertOk()->assertJsonPath('recipe.needs_review', false)->assertJsonPath('recipe.ingredients.0.quantity', '1.0000');
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'recipes' => [[
                'source_id' => 'recipe-page-1', 'title' => 'Lemon pasta', 'category' => 'Dinner',
                'instructions' => ['Boil pasta.', 'Finish with lemon.'], 'servings' => 4,
                'ingredients' => [['name' => 'Sugar', 'quantity' => '0.1', 'unit' => 'kg']],
            ]],
            'meal_plans' => [[
                'source_id' => 'week-page-1', 'planned_for' => '2026-05-04', 'meal_type' => 'dinner',
                'dish_name' => 'Lemon pasta', 'servings' => 4, 'notes' => 'Use the garden lemons.',
            ]],
        ];
    }

    private function member(string $name, bool $isAdmin = false): User
    {
        HouseholdSettings::query()->whereKey(1)->update(['setup_completed_at' => now()]);

        return User::factory()->create(['name' => $name, 'email' => str_replace(' ', '-', strtolower($name)).'@example.test', 'is_admin' => $isAdmin, 'is_active' => true]);
    }

    private function enable(User $admin, string $module): void
    {
        app(ModuleManagementService::class)->setEnabled($admin, $module, true);
    }

    /** @param array{view?: bool, create?: bool, edit?: bool, manage?: bool} $capabilities */
    private function grant(User $member, string $module, array $capabilities): void
    {
        ModuleUserPermission::query()->updateOrCreate(
            ['user_id' => $member->id, 'module_key' => $module],
            ['can_view' => $capabilities['view'] ?? false, 'can_create' => $capabilities['create'] ?? false, 'can_edit' => $capabilities['edit'] ?? false, 'can_manage' => $capabilities['manage'] ?? false],
        );
    }
}
