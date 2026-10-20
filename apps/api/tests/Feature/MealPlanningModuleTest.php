<?php

namespace Tests\Feature;

use App\Enums\ModuleCapability;
use App\Models\HouseholdSettings;
use App\Models\MealPlanSlot;
use App\Models\ModuleUserPermission;
use App\Models\User;
use App\Services\Modules\ModuleAccessService;
use App\Services\Modules\ModuleManagementService;
use App\Services\Modules\ModuleStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MealPlanningModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_free_text_plans_work_without_recipes_and_support_week_edit_and_removal(): void
    {
        $admin = $this->member('Meal Admin', true);
        $owner = $this->member('Meal Planner');
        $this->grant($admin, 'meal_planning', ['manage' => true]);
        $this->grant($owner, 'meal_planning', ['view' => true, 'create' => true, 'edit' => true]);
        $this->enable($admin, 'meal_planning');

        $this->assertFalse(app(ModuleStateService::class)->isEnabled('recipes'));
        $created = $this->actingAs($owner, 'web')->postJson('/api/meal-planning/slots', [
            'planned_for' => '2026-10-19', 'meal_type' => 'dinner', 'dish_name' => 'Lemon pasta', 'servings' => 4,
        ])->assertCreated()
            ->assertJsonPath('slot.dish_type', 'free_text')
            ->assertJsonPath('slot.dish_label', 'Lemon pasta')
            ->assertJsonPath('slot.recipe', null)
            ->assertJsonPath('slot.visibility', 'private');
        $slotId = (int) $created->json('slot.id');

        $this->actingAs($owner, 'web')->getJson('/api/meal-planning?week_start=2026-10-19')
            ->assertOk()->assertJsonPath('week_start', '2026-10-19')->assertJsonPath('week_end', '2026-10-25')
            ->assertJsonCount(1, 'slots')->assertJsonPath('slots.0.dish_label', 'Lemon pasta');
        $this->actingAs($owner, 'web')->getJson('/api/meal-planning?week_start=2026-10-20')->assertUnprocessable();

        $this->actingAs($owner, 'web')->patchJson("/api/meal-planning/slots/{$slotId}", [
            'planned_for' => '2026-10-20', 'meal_type' => 'lunch', 'dish_name' => 'Herb soup', 'servings' => 2,
        ])->assertOk()->assertJsonPath('slot.dish_label', 'Herb soup')->assertJsonPath('slot.servings', 2);
        $this->actingAs($owner, 'web')->postJson('/api/meal-planning/slots', [
            'planned_for' => '2026-10-20', 'meal_type' => 'lunch', 'dish_name' => 'Duplicate', 'servings' => 2,
        ])->assertUnprocessable();
        app(ModuleManagementService::class)->setEnabled($admin, 'meal_planning', false);
        $this->actingAs($owner, 'web')->getJson('/api/meal-planning?week_start=2026-10-19')->assertNotFound();
        $this->assertDatabaseHas('meal_plan_slots', ['id' => $slotId, 'dish_name' => 'Herb soup']);
        app(ModuleManagementService::class)->setEnabled($admin, 'meal_planning', true);
        $this->actingAs($owner, 'web')->getJson('/api/meal-planning?week_start=2026-10-19')
            ->assertOk()->assertJsonPath('slots.0.dish_label', 'Herb soup');
        $this->actingAs($owner, 'web')->deleteJson("/api/meal-planning/slots/{$slotId}")->assertOk();
        $this->assertDatabaseCount('meal_plan_slots', 0);
    }

    public function test_private_slots_remain_private_and_household_slots_require_admin_manage(): void
    {
        $admin = $this->member('Meal Plan Admin', true);
        $owner = $this->member('Private Planner');
        $viewer = $this->member('Meal Plan Viewer');
        $this->grant($admin, 'meal_planning', ['view' => true, 'manage' => true]);
        $this->grant($owner, 'meal_planning', ['view' => true, 'create' => true, 'edit' => true]);
        $this->grant($viewer, 'meal_planning', ['view' => true, 'edit' => true]);
        $this->enable($admin, 'meal_planning');

        $privateId = (int) $this->actingAs($owner, 'web')->postJson('/api/meal-planning/slots', [
            'planned_for' => '2026-10-19', 'meal_type' => 'breakfast', 'dish_name' => 'Private oats', 'servings' => 1,
        ])->assertCreated()->json('slot.id');
        $this->actingAs($admin, 'web')->getJson('/api/meal-planning?week_start=2026-10-19')->assertOk()->assertJsonCount(0, 'slots');
        $this->actingAs($viewer, 'web')->getJson('/api/meal-planning?week_start=2026-10-19')->assertOk()->assertJsonCount(0, 'slots');
        $this->actingAs($viewer, 'web')->patchJson("/api/meal-planning/slots/{$privateId}", ['dish_name' => 'No access'])
            ->assertNotFound();

        $this->actingAs($owner, 'web')->postJson('/api/meal-planning/slots', [
            'planned_for' => '2026-10-19', 'meal_type' => 'lunch', 'dish_name' => 'Shared lunch', 'servings' => 3,
            'visibility' => 'household',
        ])->assertForbidden();

        $sharedId = (int) $this->actingAs($admin, 'web')->postJson('/api/meal-planning/slots', [
            'planned_for' => '2026-10-19', 'meal_type' => 'lunch', 'dish_name' => 'Shared lunch', 'servings' => 3,
            'visibility' => 'household',
        ])->assertCreated()->json('slot.id');
        $this->actingAs($viewer, 'web')->getJson('/api/meal-planning?week_start=2026-10-19')
            ->assertOk()->assertJsonCount(1, 'slots')->assertJsonPath('slots.0.dish_label', 'Shared lunch');
        $this->actingAs($viewer, 'web')->patchJson("/api/meal-planning/slots/{$sharedId}", ['dish_name' => 'Unauthorized'])
            ->assertForbidden();
        $this->actingAs($admin, 'web')->patchJson("/api/meal-planning/slots/{$sharedId}", ['dish_name' => 'Updated shared lunch'])
            ->assertOk()->assertJsonPath('slot.dish_label', 'Updated shared lunch');
    }

    public function test_recipe_link_requires_recipe_access_and_never_exposes_a_private_title_through_a_meal_slot(): void
    {
        $admin = $this->member('Recipe Link Admin', true);
        $owner = $this->member('Private Recipe Owner');
        $viewer = $this->member('Meal Slot Viewer');
        $this->grant($admin, 'meal_planning', ['manage' => true]);
        $this->grant($owner, 'meal_planning', ['view' => true, 'create' => true, 'edit' => true, 'manage' => true]);
        $this->grant($viewer, 'meal_planning', ['view' => true]);
        $this->grant($owner, 'recipes', ['view' => true, 'create' => true, 'edit' => true]);
        $this->enable($admin, 'meal_planning');
        $this->enable($admin, 'recipes');

        $recipeId = (int) $this->actingAs($owner, 'web')->postJson('/api/recipes', $this->recipePayload())
            ->assertCreated()->json('recipe.id');
        $privateId = (int) $this->actingAs($owner, 'web')->postJson('/api/meal-planning/slots', [
            'planned_for' => '2026-10-19', 'meal_type' => 'dinner', 'dish_type' => 'recipe', 'recipe_id' => $recipeId,
            'servings' => 4,
        ])->assertCreated()->assertJsonPath('slot.recipe.title', 'Private recipe: saffron pear')->json('slot.id');

        $slot = MealPlanSlot::query()->findOrFail($privateId);
        app(ModuleAccessService::class)->grantPrivateResourceAccess($owner, $slot, $viewer, ModuleCapability::View);
        $viewerPlan = $this->actingAs($viewer, 'web')->getJson('/api/meal-planning?week_start=2026-10-19')
            ->assertOk()->assertJsonCount(1, 'slots')->assertJsonPath('slots.0.dish_label', 'Linked recipe')
            ->assertJsonPath('slots.0.recipe', null);
        $this->assertStringNotContainsString('Private recipe: saffron pear', $viewerPlan->getContent());

        app(ModuleManagementService::class)->setEnabled($admin, 'recipes', false);
        $this->actingAs($viewer, 'web')->getJson('/api/meal-planning?week_start=2026-10-19')
            ->assertOk()->assertJsonPath('slots.0.dish_label', 'Linked recipe')->assertJsonPath('slots.0.recipe', null);
        $this->actingAs($owner, 'web')->deleteJson("/api/recipes/{$recipeId}")->assertNotFound();
        $this->assertDatabaseHas('meal_plan_slots', ['id' => $privateId, 'dish_type' => 'recipe', 'recipe_id' => $recipeId]);
        $this->assertDatabaseHas('recipes', ['id' => $recipeId, 'title' => 'Private recipe: saffron pear']);

        app(ModuleManagementService::class)->setEnabled($admin, 'recipes', true);
        $this->actingAs($owner, 'web')->deleteJson("/api/recipes/{$recipeId}")->assertOk();
        $this->assertDatabaseHas('meal_plan_slots', ['id' => $privateId, 'dish_type' => 'recipe', 'recipe_id' => null]);
        $this->actingAs($viewer, 'web')->getJson('/api/meal-planning?week_start=2026-10-19')
            ->assertOk()->assertJsonPath('slots.0.dish_label', 'Recipe no longer available');
    }

    public function test_invalid_recipe_links_and_inconsistent_dish_payloads_are_rejected(): void
    {
        $admin = $this->member('Validation Meal Admin', true);
        $member = $this->member('Validation Meal Planner');
        $this->grant($admin, 'meal_planning', ['manage' => true]);
        $this->grant($member, 'meal_planning', ['view' => true, 'create' => true]);
        $this->enable($admin, 'meal_planning');

        $this->actingAs($member, 'web')->postJson('/api/meal-planning/slots', [
            'planned_for' => '2026-10-19', 'meal_type' => 'dinner', 'dish_type' => 'recipe', 'recipe_id' => 88,
            'servings' => 2,
        ])->assertUnprocessable();
        $this->grant($member, 'recipes', ['view' => true]);
        $this->enable($admin, 'recipes');
        $this->actingAs($member, 'web')->postJson('/api/meal-planning/slots', [
            'planned_for' => '2026-10-19', 'meal_type' => 'dinner', 'dish_type' => 'recipe', 'recipe_id' => 88,
            'servings' => 2,
        ])->assertNotFound();
        $this->actingAs($member, 'web')->postJson('/api/meal-planning/slots', [
            'planned_for' => '2026-10-19', 'meal_type' => 'dinner', 'dish_type' => 'free_text', 'recipe_id' => 88,
            'dish_name' => 'Conflicting input', 'servings' => 2,
        ])->assertUnprocessable();
        $this->actingAs($member, 'web')->postJson('/api/meal-planning/slots', [
            'planned_for' => '2026-10-19', 'meal_type' => 'dinner', 'dish_name' => 'No servings', 'servings' => 0,
        ])->assertUnprocessable();
        $this->assertDatabaseCount('meal_plan_slots', 0);
    }

    /** @return array<string, mixed> */
    private function recipePayload(): array
    {
        return [
            'title' => 'Private recipe: saffron pear', 'instructions' => 'Prepared privately.', 'servings' => 2,
            'ingredients' => [['name' => 'Pear', 'quantity' => '1', 'unit' => 'piece']],
        ];
    }

    private function member(string $name, bool $isAdmin = false): User
    {
        HouseholdSettings::query()->whereKey(1)->update(['setup_completed_at' => now()]);

        return User::factory()->create([
            'name' => $name,
            'email' => str_replace(' ', '-', strtolower($name)).'@example.test',
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
