<?php

namespace Tests\Feature;

use App\Enums\ModuleCapability;
use App\Models\HouseholdSettings;
use App\Models\ModuleUserPermission;
use App\Models\ShoppingList;
use App\Models\User;
use App\Services\Modules\ModuleAccessService;
use App\Services\Modules\ModuleManagementService;
use App\Services\Modules\ModuleStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShoppingModuleTest extends TestCase
{
    use RefreshDatabase;

    private int $memberSequence = 0;

    public function test_shopping_runs_without_other_food_modules_and_restores_checked_items_with_history(): void
    {
        $admin = $this->member(true);
        $owner = $this->member();
        $this->grant($admin, ['view' => true, 'manage' => true]);
        $this->grant($owner, ['view' => true, 'create' => true, 'edit' => true, 'manage' => true]);
        $this->enable($admin, true);

        $states = app(ModuleStateService::class);
        $this->assertFalse($states->isEnabled('recipes'));
        $this->assertFalse($states->isEnabled('meal_planning'));
        $this->assertFalse($states->isEnabled('pantry'));

        $list = $this->actingAs($owner, 'web')->postJson('/api/shopping/lists', ['name' => 'Weekend shop'])
            ->assertCreated()->assertJsonPath('list.visibility', 'private')->assertJsonPath('list.can_add_items', true);
        $listId = (int) $list->json('list.id');
        $first = $this->addItem($owner, $listId, 'Apples', '1.5', 'kg')
            ->assertCreated()->assertJsonPath('item.quantity', '1.500')->assertJsonPath('item.unit', 'kg');
        $firstId = (int) $first->json('item.id');
        $second = $this->addItem($owner, $listId, 'Oat milk')
            ->assertCreated()->assertJsonPath('item.quantity', null)->assertJsonPath('item.unit', null);
        $secondId = (int) $second->json('item.id');

        $this->actingAs($owner, 'web')->postJson("/api/shopping/items/{$secondId}/move", [
            'expected_version' => 1, 'direction' => 'up',
        ])->assertOk();
        $this->actingAs($owner, 'web')->getJson("/api/shopping/lists/{$listId}")
            ->assertOk()->assertJsonPath('items.0.name', 'Oat milk')->assertJsonPath('items.1.name', 'Apples');

        $this->actingAs($owner, 'web')->patchJson("/api/shopping/items/{$firstId}/checked", [
            'expected_version' => 2, 'checked' => true,
        ])->assertOk()->assertJsonPath('item.is_checked', true)->assertJsonPath('item.version', 3);
        $this->actingAs($owner, 'web')->patchJson("/api/shopping/items/{$firstId}/checked", [
            'expected_version' => 2, 'checked' => false,
        ])->assertStatus(409);
        $this->actingAs($owner, 'web')->patchJson("/api/shopping/items/{$firstId}/checked", [
            'expected_version' => 3, 'checked' => false,
        ])->assertOk()->assertJsonPath('item.is_checked', false)->assertJsonPath('item.version', 4);
        $this->actingAs($owner, 'web')->patchJson("/api/shopping/items/{$firstId}", [
            'expected_version' => 3, 'name' => 'Stale item update',
        ])->assertStatus(409);
        $this->actingAs($owner, 'web')->patchJson("/api/shopping/items/{$firstId}", [
            'expected_version' => 4, 'name' => 'Green apples',
        ])->assertOk()->assertJsonPath('item.name', 'Green apples')->assertJsonPath('item.version', 5);

        $this->addItem($owner, $listId, 'Missing unit', '2', null)->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->addItem($owner, $listId, 'Zero quantity', '0', 'kg')->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->actingAs($owner, 'web')->deleteJson("/api/shopping/items/{$secondId}")->assertOk();
        $history = $this->actingAs($owner, 'web')->getJson("/api/shopping/lists/{$listId}/history")->assertOk()->json('events');
        $types = collect($history)->pluck('type');
        $this->assertContains('item_checked', $types);
        $this->assertContains('item_unchecked', $types);
        $this->assertContains('item_reordered', $types);
        $removed = collect($history)->firstWhere('type', 'item_deleted');
        $this->assertSame('Oat milk', $removed['before']['name']);
        $this->assertNull($removed['after']);
        $this->assertNull($removed['item_id']);
        $this->assertDatabaseHas('shopping_items', ['id' => $firstId, 'is_checked' => false, 'quantity' => '1.500']);
    }

    public function test_private_lists_and_activity_require_ownership_or_exact_resource_grants(): void
    {
        $admin = $this->member(true);
        $owner = $this->member();
        $viewer = $this->member();
        $this->grant($admin, ['view' => true, 'manage' => true]);
        $this->grant($owner, ['view' => true, 'create' => true, 'edit' => true, 'manage' => true]);
        $this->grant($viewer, ['view' => true, 'edit' => true]);
        $this->enable($admin, true);
        $listId = (int) $this->actingAs($owner, 'web')->postJson('/api/shopping/lists', [
            'name' => 'Private supplies',
        ])->assertCreated()->json('list.id');
        $itemId = (int) $this->addItem($owner, $listId, 'Private tea')->assertCreated()->json('item.id');

        $this->actingAs($admin, 'web')->getJson('/api/shopping/lists')->assertOk()->assertJsonCount(0, 'lists');
        $this->actingAs($admin, 'web')->getJson("/api/shopping/lists/{$listId}")->assertNotFound();
        $this->actingAs($viewer, 'web')->getJson('/api/shopping/lists')->assertOk()->assertJsonCount(0, 'lists');

        $list = ShoppingList::query()->findOrFail($listId);
        $access = app(ModuleAccessService::class);
        $access->grantPrivateResourceAccess($owner, $list, $viewer, ModuleCapability::View);
        $this->actingAs($viewer, 'web')->getJson('/api/shopping/lists')->assertOk()
            ->assertJsonCount(1, 'lists')->assertJsonPath('lists.0.can_edit', false);
        $this->actingAs($viewer, 'web')->getJson("/api/shopping/lists/{$listId}/history")->assertOk();
        $this->actingAs($viewer, 'web')->patchJson("/api/shopping/items/{$itemId}", [
            'expected_version' => 1, 'name' => 'No edit grant',
        ])->assertNotFound();

        $access->grantPrivateResourceAccess($owner, $list, $viewer, ModuleCapability::Edit);
        $this->actingAs($viewer, 'web')->getJson('/api/shopping/lists')->assertOk()->assertJsonPath('lists.0.can_edit', true);
        $this->actingAs($viewer, 'web')->patchJson("/api/shopping/items/{$itemId}", [
            'expected_version' => 1, 'name' => 'Shared by exact edit grant',
        ])->assertOk()->assertJsonPath('item.name', 'Shared by exact edit grant');
        $this->actingAs($admin, 'web')->getJson('/api/shopping/lists')->assertOk()->assertJsonCount(0, 'lists');
    }

    public function test_household_list_allows_members_with_item_edit_but_not_view_only_members(): void
    {
        $admin = $this->member(true);
        $editor = $this->member();
        $reader = $this->member();
        $this->enable($admin, true);
        $this->grant($admin, ['view' => true, 'manage' => true]);
        $this->grant($editor, ['view' => true, 'create' => true, 'edit' => true]);
        $this->grant($reader, ['view' => true]);

        $this->actingAs($editor, 'web')->postJson('/api/shopping/lists', [
            'name' => 'Unauthorized shared list', 'visibility' => 'household',
        ])->assertForbidden();
        $list = $this->actingAs($admin, 'web')->postJson('/api/shopping/lists', [
            'name' => 'Household groceries', 'visibility' => 'household',
        ])->assertCreated()->assertJsonPath('list.can_edit', true);
        $listId = (int) $list->json('list.id');
        $itemId = (int) $this->addItem($editor, $listId, 'Bread', '2', 'loaves')
            ->assertCreated()->assertJsonPath('item.can_edit', true)->json('item.id');

        $this->actingAs($reader, 'web')->getJson("/api/shopping/lists/{$listId}")
            ->assertOk()->assertJsonPath('list.can_edit', false)->assertJsonCount(1, 'items');
        $this->actingAs($reader, 'web')->patchJson("/api/shopping/items/{$itemId}/checked", [
            'expected_version' => 1, 'checked' => true,
        ])->assertForbidden();
        $this->actingAs($admin, 'web')->patchJson("/api/shopping/items/{$itemId}/checked", [
            'expected_version' => 1, 'checked' => true,
        ])->assertOk()->assertJsonPath('item.is_checked', true);
        $this->actingAs($editor, 'web')->patchJson("/api/shopping/items/{$itemId}/checked", [
            'expected_version' => 2, 'checked' => false,
        ])->assertOk()->assertJsonPath('item.is_checked', false);
    }

    public function test_disabling_shopping_blocks_api_and_preserves_lists_items_and_history(): void
    {
        $admin = $this->member(true);
        $this->grant($admin, ['view' => true, 'create' => true, 'edit' => true, 'manage' => true]);
        $this->enable($admin, true);
        $listId = (int) $this->actingAs($admin, 'web')->postJson('/api/shopping/lists', [
            'name' => 'Saved for later',
        ])->assertCreated()->json('list.id');
        $itemId = (int) $this->addItem($admin, $listId, 'Pasta')->assertCreated()->json('item.id');

        $this->enable($admin, false);
        $this->actingAs($admin, 'web')->getJson('/api/shopping/lists')->assertNotFound();
        $this->actingAs($admin, 'web')->patchJson("/api/shopping/items/{$itemId}/checked", [
            'expected_version' => 1, 'checked' => true,
        ])->assertNotFound();
        $this->assertDatabaseHas('shopping_lists', ['id' => $listId, 'name' => 'Saved for later']);
        $this->assertDatabaseHas('shopping_items', ['id' => $itemId, 'name' => 'Pasta']);
        $this->assertDatabaseHas('shopping_list_events', ['shopping_list_id' => $listId, 'event_type' => 'item_added']);

        $this->enable($admin, true);
        $this->actingAs($admin, 'web')->getJson("/api/shopping/lists/{$listId}")
            ->assertOk()->assertJsonPath('items.0.name', 'Pasta');
    }

    private function addItem(User $member, int $listId, string $name, ?string $quantity = null, ?string $unit = null)
    {
        return $this->actingAs($member, 'web')->postJson("/api/shopping/lists/{$listId}/items", [
            'name' => $name, 'quantity' => $quantity, 'unit' => $unit,
        ]);
    }

    private function member(bool $admin = false): User
    {
        HouseholdSettings::query()->whereKey(1)->update(['setup_completed_at' => now()]);
        $this->memberSequence++;

        return User::factory()->create([
            'name' => 'Shopping Member '.$this->memberSequence,
            'email' => 'shopping-'.$this->memberSequence.'@example.test',
            'is_admin' => $admin,
            'is_active' => true,
        ]);
    }

    /** @param array{view?: bool, create?: bool, edit?: bool, manage?: bool} $capabilities */
    private function grant(User $member, array $capabilities): void
    {
        ModuleUserPermission::query()->updateOrCreate(
            ['user_id' => $member->id, 'module_key' => 'shopping'],
            [
                'can_view' => $capabilities['view'] ?? false,
                'can_create' => $capabilities['create'] ?? false,
                'can_edit' => $capabilities['edit'] ?? false,
                'can_manage' => $capabilities['manage'] ?? false,
            ],
        );
    }

    private function enable(User $admin, bool $enabled): void
    {
        app(ModuleManagementService::class)->setEnabled($admin, 'shopping', $enabled);
    }
}
