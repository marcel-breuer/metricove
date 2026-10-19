<?php

namespace Tests\Feature;

use App\Enums\ModuleCapability;
use App\Models\HouseholdSettings;
use App\Models\ModuleUserPermission;
use App\Models\Recipe;
use App\Models\User;
use App\Services\Modules\ModuleAccessService;
use App\Services\Modules\ModuleManagementService;
use App\Services\Modules\ModuleStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RecipesModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_recipes_support_crud_ordered_quantities_and_member_favorites(): void
    {
        $admin = $this->member('Recipe Admin', true);
        $owner = $this->member('Recipe Owner');
        $other = $this->member('Recipe Reader');
        $this->grant($admin, ['manage' => true]);
        $this->grant($owner, ['view' => true, 'create' => true, 'edit' => true]);
        $this->grant($other, ['view' => true]);
        $this->enableRecipes($admin);

        $created = $this->actingAs($owner, 'web')->postJson('/api/recipes', $this->payload());
        $created->assertCreated()
            ->assertJsonPath('recipe.title', 'Lemon pasta')
            ->assertJsonPath('recipe.servings', 4)
            ->assertJsonPath('recipe.ingredients.0.quantity', '0.1250')
            ->assertJsonPath('recipe.ingredients.1.quantity', '250.0000')
            ->assertJsonPath('recipe.visibility', 'private')
            ->assertJsonPath('recipe.is_favorite', false);
        $recipeId = (int) $created->json('recipe.id');

        $this->actingAs($owner, 'web')->patchJson("/api/recipes/{$recipeId}", [
            'title' => 'Lemon and herb pasta', 'servings' => 2,
            'ingredients' => [
                ['name' => 'Pasta', 'quantity' => '125', 'unit' => 'g'],
                ['name' => 'Lemon', 'quantity' => '0.5', 'unit' => 'piece', 'note' => 'zested'],
            ],
        ])->assertOk()
            ->assertJsonPath('recipe.title', 'Lemon and herb pasta')
            ->assertJsonPath('recipe.servings', 2)
            ->assertJsonPath('recipe.ingredients.0.name', 'Pasta')
            ->assertJsonPath('recipe.ingredients.1.quantity', '0.5000')
            ->assertJsonPath('recipe.ingredients.1.note', 'zested');

        $this->actingAs($other, 'web')->getJson('/api/recipes')->assertOk()->assertJsonCount(0, 'recipes');
        $this->actingAs($owner, 'web')->putJson("/api/recipes/{$recipeId}/favorite")
            ->assertOk()->assertJsonPath('recipe.is_favorite', true);
        $this->actingAs($owner, 'web')->putJson("/api/recipes/{$recipeId}/favorite")
            ->assertOk()->assertJsonPath('recipe.is_favorite', true);
        $this->actingAs($other, 'web')->getJson("/api/recipes/{$recipeId}")->assertNotFound();
        $this->actingAs($owner, 'web')->deleteJson("/api/recipes/{$recipeId}/favorite")->assertOk();
        $this->actingAs($owner, 'web')->deleteJson("/api/recipes/{$recipeId}")->assertOk();
        $this->assertDatabaseCount('recipes', 0);
        $this->assertDatabaseCount('recipe_ingredients', 0);
        $this->assertDatabaseCount('recipe_favorites', 0);
    }

    public function test_recipe_quantities_servings_and_ingredient_counts_are_validated(): void
    {
        $admin = $this->member('Recipe Validation Admin', true);
        $member = $this->member('Recipe Author');
        $this->grant($admin, ['manage' => true]);
        $this->grant($member, ['create' => true]);
        $this->enableRecipes($admin);

        foreach ([
            ['servings' => 0, 'ingredients' => $this->payload()['ingredients']],
            ['servings' => 1001, 'ingredients' => $this->payload()['ingredients']],
            ['servings' => 2, 'ingredients' => [['name' => 'Salt', 'quantity' => '0', 'unit' => 'g']]],
            ['servings' => 2, 'ingredients' => [['name' => 'Salt', 'quantity' => '1.00001', 'unit' => 'g']]],
            ['servings' => 2, 'ingredients' => []],
        ] as $invalid) {
            $payload = array_merge($this->payload(), $invalid);
            $response = $this->actingAs($member, 'web')->postJson('/api/recipes', $payload)->assertUnprocessable();
            $this->assertNotEmpty($response->json('errors'));
        }

        $this->assertDatabaseCount('recipes', 0);
        $this->assertDatabaseCount('recipe_ingredients', 0);
    }

    public function test_private_and_household_visibility_follow_module_and_resource_permissions(): void
    {
        $admin = $this->member('Recipe Admin', true);
        $owner = $this->member('Private Recipe Owner');
        $viewer = $this->member('Recipe Viewer');
        $this->grant($admin, ['view' => true, 'manage' => true]);
        $this->grant($owner, ['view' => true, 'create' => true, 'manage' => true]);
        $this->grant($viewer, ['view' => true, 'edit' => true]);
        $this->enableRecipes($admin);

        $privateId = (int) $this->actingAs($owner, 'web')->postJson('/api/recipes', $this->payload())
            ->assertCreated()->json('recipe.id');
        $this->actingAs($admin, 'web')->getJson('/api/recipes')->assertOk()->assertJsonCount(0, 'recipes');
        $this->actingAs($admin, 'web')->getJson("/api/recipes/{$privateId}")->assertNotFound();
        $this->actingAs($owner, 'web')->postJson('/api/recipes', array_merge($this->payload(), ['visibility' => 'household']))
            ->assertForbidden();
        $this->actingAs($owner, 'web')->patchJson("/api/recipes/{$privateId}", ['title' => 'Denied without edit'])
            ->assertForbidden();

        $recipe = Recipe::query()->findOrFail($privateId);
        $this->grant($owner, ['view' => true, 'create' => true, 'edit' => true, 'manage' => true]);
        app(ModuleAccessService::class)->grantPrivateResourceAccess($owner, $recipe, $viewer, ModuleCapability::View);
        app(ModuleAccessService::class)->grantPrivateResourceAccess($owner, $recipe, $viewer, ModuleCapability::Edit);
        $this->actingAs($viewer, 'web')->getJson('/api/recipes')->assertOk()->assertJsonCount(1, 'recipes');
        $this->actingAs($viewer, 'web')->patchJson("/api/recipes/{$privateId}", ['title' => 'Shared by owner'])
            ->assertOk()->assertJsonPath('recipe.title', 'Shared by owner');
        $this->actingAs($admin, 'web')->getJson('/api/recipes')->assertOk()->assertJsonCount(0, 'recipes');

        $sharedId = (int) $this->actingAs($admin, 'web')->postJson('/api/recipes', array_merge($this->payload(), [
            'title' => 'Household soup', 'visibility' => 'household',
        ]))->assertCreated()->json('recipe.id');
        $this->actingAs($viewer, 'web')->getJson('/api/recipes')->assertOk()->assertJsonCount(2, 'recipes')
            ->assertJsonFragment(['id' => $sharedId]);
        $this->actingAs($viewer, 'web')->patchJson("/api/recipes/{$sharedId}", ['title' => 'Unauthorized shared edit'])
            ->assertForbidden();
        $this->actingAs($admin, 'web')->patchJson("/api/recipes/{$sharedId}", ['title' => 'Updated shared soup'])
            ->assertOk()->assertJsonPath('recipe.title', 'Updated shared soup');
    }

    public function test_recipe_photos_use_private_storage_and_require_recipe_access(): void
    {
        Storage::fake('local');
        $owner = $this->member('Photo Owner');
        $admin = $this->member('Photo Admin', true);
        $this->grant($owner, ['view' => true, 'create' => true, 'edit' => true]);
        $this->grant($admin, ['view' => true, 'manage' => true]);
        $this->enableRecipes($admin);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/vN8AAAAASUVORK5CYII=', true);

        $uploadPayload = array_merge($this->payload(), [
            'photo' => UploadedFile::fake()->createWithContent('recipe.png', $png),
        ]);
        $uploadPayload['ingredients'] = json_encode($uploadPayload['ingredients'], JSON_THROW_ON_ERROR);
        $created = $this->actingAs($owner, 'web')->post('/api/recipes', $uploadPayload, ['Accept' => 'application/json'])->assertCreated();
        $recipeId = (int) $created->json('recipe.id');
        $path = Recipe::query()->findOrFail($recipeId)->photo_path;
        $this->assertNotNull($path);
        Storage::disk('local')->assertExists($path);
        $this->assertStringNotContainsString($path, $created->json('recipe.photo_url'));
        $this->actingAs($admin, 'web')->get("/api/recipes/{$recipeId}/photo")->assertNotFound();
        $this->actingAs($owner, 'web')->get("/api/recipes/{$recipeId}/photo")
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private');

        $update = $this->actingAs($owner, 'web')->post("/api/recipes/{$recipeId}", [
            '_method' => 'PATCH', 'servings' => '5',
            'ingredients' => json_encode([
                ['name' => 'Pasta', 'quantity' => '125.5', 'unit' => 'g'],
            ], JSON_THROW_ON_ERROR),
        ], ['Accept' => 'application/json'])->assertOk();
        $update->assertJsonPath('recipe.servings', 5)
            ->assertJsonPath('recipe.ingredients.0.quantity', '125.5000');
        Storage::disk('local')->assertExists($path);

        $this->actingAs($owner, 'web')->post("/api/recipes/{$recipeId}", [
            '_method' => 'PATCH', 'remove_photo' => '1',
        ], ['Accept' => 'application/json'])->assertOk();
        Storage::disk('local')->assertMissing($path);
        $this->assertNull(Recipe::query()->findOrFail($recipeId)->photo_path);
    }

    public function test_recipes_work_when_other_food_modules_are_disabled_and_data_survives_disable(): void
    {
        Storage::fake('local');
        $admin = $this->member('Standalone Recipe Admin', true);
        $this->grant($admin, ['view' => true, 'create' => true, 'edit' => true, 'manage' => true]);
        $this->enableRecipes($admin);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/vN8AAAAASUVORK5CYII=', true);
        $uploadPayload = array_merge($this->payload(), [
            'photo' => UploadedFile::fake()->createWithContent('recipe.png', $png),
        ]);
        $uploadPayload['ingredients'] = json_encode($uploadPayload['ingredients'], JSON_THROW_ON_ERROR);
        $created = $this->actingAs($admin, 'web')->post('/api/recipes', $uploadPayload, ['Accept' => 'application/json'])->assertCreated();
        $recipeId = (int) $created->json('recipe.id');
        $photoPath = Recipe::query()->findOrFail($recipeId)->photo_path;
        $this->assertNotNull($photoPath);
        Storage::disk('local')->assertExists($photoPath);
        $this->actingAs($admin, 'web')->putJson("/api/recipes/{$recipeId}/favorite")->assertOk();

        $this->assertFalse(app(ModuleStateService::class)->isEnabled('meal_planning'));
        $this->assertFalse(app(ModuleStateService::class)->isEnabled('pantry'));
        $this->assertFalse(app(ModuleStateService::class)->isEnabled('shopping'));
        app(ModuleManagementService::class)->setEnabled($admin, 'recipes', false);
        $this->actingAs($admin, 'web')->getJson('/api/recipes')->assertNotFound();
        $this->assertDatabaseHas('recipes', ['id' => $recipeId, 'title' => 'Lemon pasta']);
        $this->assertDatabaseHas('recipe_ingredients', ['recipe_id' => $recipeId, 'name' => 'Black pepper']);
        $this->assertDatabaseHas('recipe_favorites', ['recipe_id' => $recipeId, 'user_id' => $admin->id]);
        Storage::disk('local')->assertExists($photoPath);
        app(ModuleManagementService::class)->setEnabled($admin, 'recipes', true);
        $this->actingAs($admin, 'web')->getJson("/api/recipes/{$recipeId}")->assertOk();
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'title' => 'Lemon pasta', 'category' => 'Dinner', 'instructions' => "Boil pasta.\nFinish with lemon.",
            'servings' => 4, 'preparation_minutes' => 25,
            'ingredients' => [
                ['name' => 'Black pepper', 'quantity' => '0.125', 'unit' => 'tsp'],
                ['name' => 'Pasta', 'quantity' => '250', 'unit' => 'g'],
            ],
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

    private function enableRecipes(User $admin): void
    {
        app(ModuleManagementService::class)->setEnabled($admin, 'recipes', true);
    }

    /** @param array{view?: bool, create?: bool, edit?: bool, manage?: bool} $capabilities */
    private function grant(User $member, array $capabilities): void
    {
        ModuleUserPermission::query()->updateOrCreate(
            ['user_id' => $member->id, 'module_key' => 'recipes'],
            [
                'can_view' => $capabilities['view'] ?? false,
                'can_create' => $capabilities['create'] ?? false,
                'can_edit' => $capabilities['edit'] ?? false,
                'can_manage' => $capabilities['manage'] ?? false,
            ],
        );
    }
}
