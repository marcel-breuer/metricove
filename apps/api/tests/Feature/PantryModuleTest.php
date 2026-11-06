<?php

namespace Tests\Feature;

use App\Enums\ModuleCapability;
use App\Models\HouseholdSettings;
use App\Models\ModuleUserPermission;
use App\Models\PantryItem;
use App\Models\User;
use App\Services\Modules\ModuleAccessService;
use App\Services\Modules\ModuleManagementService;
use App\Services\Modules\ModuleStateService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PantryModuleTest extends TestCase
{
    use RefreshDatabase;

    private int $memberSequence = 0;

    public function test_private_stock_uses_exact_quantities_history_expiry_status_and_version_conflicts(): void
    {
        $admin = $this->member(true);
        $owner = $this->member();
        $this->grant($admin, ['view' => true, 'manage' => true]);
        $this->grant($owner, ['view' => true, 'create' => true, 'edit' => true]);
        $this->enable($admin, true);
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00:00'));

        $this->assertFalse(app(ModuleStateService::class)->isEnabled('recipes'));
        $this->assertFalse(app(ModuleStateService::class)->isEnabled('meal_planning'));
        $this->assertFalse(app(ModuleStateService::class)->isEnabled('shopping'));

        $created = $this->item($owner, 'Oat milk', '1.5', 'l', 'Fridge', '2026-10-23')
            ->assertCreated()
            ->assertJsonPath('item.quantity', '1.500')
            ->assertJsonPath('item.unit', 'l')
            ->assertJsonPath('item.visibility', 'private')
            ->assertJsonPath('item.version', 1)
            ->assertJsonPath('item.expiry_status', 'soon');
        $itemId = (int) $created->json('item.id');

        $this->actingAs($owner, 'web')->postJson("/api/pantry/{$itemId}/adjustments", [
            'expected_version' => 1, 'quantity_delta' => '0.250', 'note' => 'Added one carton',
        ])->assertOk()
            ->assertJsonPath('item.quantity', '1.750')
            ->assertJsonPath('item.version', 2)
            ->assertJsonPath('movement.quantity_before', '1.500')
            ->assertJsonPath('movement.quantity_delta', '0.250')
            ->assertJsonPath('movement.quantity_after', '1.750');

        $this->actingAs($owner, 'web')->postJson("/api/pantry/{$itemId}/adjustments", [
            'expected_version' => 1, 'quantity_delta' => '-0.100',
        ])->assertStatus(409);
        $this->actingAs($owner, 'web')->postJson("/api/pantry/{$itemId}/adjustments", [
            'expected_version' => 2, 'quantity_delta' => '-2.000',
        ])->assertUnprocessable()->assertJsonValidationErrors('quantity_delta');
        $this->actingAs($owner, 'web')->postJson("/api/pantry/{$itemId}/adjustments", [
            'expected_version' => 2, 'quantity_delta' => '0.0001',
        ])->assertUnprocessable()->assertJsonValidationErrors('quantity_delta');
        $this->actingAs($owner, 'web')->patchJson("/api/pantry/{$itemId}", [
            'expected_version' => 1, 'location' => 'Cupboard',
        ])->assertStatus(409);

        $this->actingAs($owner, 'web')->postJson("/api/pantry/{$itemId}/adjustments", [
            'expected_version' => 2, 'quantity_delta' => '-0.250',
        ])->assertOk()->assertJsonPath('item.quantity', '1.500')->assertJsonPath('item.version', 3);
        $this->actingAs($owner, 'web')->patchJson("/api/pantry/{$itemId}", [
            'expected_version' => 3, 'location' => 'Cupboard', 'best_before_on' => '2026-10-20',
        ])->assertOk()->assertJsonPath('item.location', 'Cupboard')->assertJsonPath('item.expiry_status', 'today');

        $this->actingAs($owner, 'web')->getJson("/api/pantry/{$itemId}/movements")
            ->assertOk()->assertJsonCount(3, 'movements')
            ->assertJsonPath('movements.0.type', 'adjustment')
            ->assertJsonPath('movements.0.quantity_delta', '-0.250')
            ->assertJsonPath('movements.1.note', 'Added one carton')
            ->assertJsonPath('movements.2.type', 'initial');
        $this->actingAs($owner, 'web')->getJson('/api/pantry?expiry=today')
            ->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.name', 'Oat milk');
        $this->actingAs($owner, 'web')->getJson('/api/pantry?expiry=soon')
            ->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.expiry_status', 'today');
    }

    public function test_private_items_and_history_require_resource_visibility_even_from_an_admin(): void
    {
        $admin = $this->member(true);
        $owner = $this->member();
        $viewer = $this->member();
        $this->grant($admin, ['view' => true, 'manage' => true]);
        $this->grant($owner, ['view' => true, 'create' => true, 'edit' => true, 'manage' => true]);
        $this->grant($viewer, ['view' => true, 'edit' => true]);
        $this->enable($admin, true);
        $itemId = (int) $this->item($owner, 'Private lentils', '2', 'kg')->assertCreated()->json('item.id');

        $this->actingAs($admin, 'web')->getJson('/api/pantry')->assertOk()->assertJsonCount(0, 'items');
        $this->actingAs($viewer, 'web')->getJson('/api/pantry')->assertOk()->assertJsonCount(0, 'items');
        $this->actingAs($admin, 'web')->getJson("/api/pantry/{$itemId}/movements")->assertNotFound();
        $this->actingAs($viewer, 'web')->patchJson("/api/pantry/{$itemId}", [
            'expected_version' => 1, 'name' => 'Should stay hidden',
        ])->assertNotFound();

        $item = PantryItem::query()->findOrFail($itemId);
        app(ModuleAccessService::class)->grantPrivateResourceAccess($owner, $item, $viewer, ModuleCapability::View);
        $this->actingAs($viewer, 'web')->getJson('/api/pantry')->assertOk()
            ->assertJsonCount(1, 'items')->assertJsonPath('items.0.name', 'Private lentils')
            ->assertJsonPath('items.0.can_edit', false);
        $this->actingAs($viewer, 'web')->getJson("/api/pantry/{$itemId}/movements")
            ->assertOk()->assertJsonCount(1, 'movements');
        $this->actingAs($viewer, 'web')->patchJson("/api/pantry/{$itemId}", [
            'expected_version' => 1, 'name' => 'No edit grant',
        ])->assertNotFound();

        app(ModuleAccessService::class)->grantPrivateResourceAccess($owner, $item, $viewer, ModuleCapability::Edit);
        $this->actingAs($viewer, 'web')->patchJson("/api/pantry/{$itemId}", [
            'expected_version' => 1, 'name' => 'Shared by exact grant',
        ])->assertOk()->assertJsonPath('item.name', 'Shared by exact grant');
        $this->actingAs($admin, 'web')->getJson('/api/pantry')->assertOk()->assertJsonCount(0, 'items');
    }

    public function test_household_stock_requires_active_admin_manage_and_is_read_only_to_members(): void
    {
        $admin = $this->member(true);
        $member = $this->member();
        $this->grant($admin, ['view' => true, 'manage' => true]);
        $this->grant($member, ['view' => true, 'create' => true, 'edit' => true, 'manage' => true]);
        $this->enable($admin, true);

        $this->item($member, 'Shared rice', '3', 'kg', visibility: 'household')->assertForbidden();
        $itemId = (int) $this->item($admin, 'Shared rice', '3', 'kg', visibility: 'household')
            ->assertCreated()->assertJsonPath('item.can_edit', true)->json('item.id');

        $this->actingAs($member, 'web')->getJson('/api/pantry')->assertOk()
            ->assertJsonCount(1, 'items')->assertJsonPath('items.0.visibility', 'household')
            ->assertJsonPath('items.0.can_edit', false);
        $this->actingAs($member, 'web')->patchJson("/api/pantry/{$itemId}", [
            'expected_version' => 1, 'name' => 'Member cannot edit shared stock',
        ])->assertForbidden();
        $this->actingAs($admin, 'web')->postJson("/api/pantry/{$itemId}/adjustments", [
            'expected_version' => 1, 'quantity_delta' => '-1.000',
        ])->assertOk()->assertJsonPath('item.quantity', '2.000');
    }

    public function test_disabling_pantry_blocks_endpoints_and_preserves_stock_and_movements(): void
    {
        $admin = $this->member(true);
        $this->grant($admin, ['view' => true, 'create' => true, 'edit' => true, 'manage' => true]);
        $this->enable($admin, true);
        $itemId = (int) $this->item($admin, 'Canned tomatoes', '4', 'cans')->assertCreated()->json('item.id');

        $this->enable($admin, false);
        $this->actingAs($admin, 'web')->getJson('/api/pantry')->assertNotFound();
        $this->actingAs($admin, 'web')->postJson("/api/pantry/{$itemId}/adjustments", [
            'expected_version' => 1, 'quantity_delta' => '1',
        ])->assertNotFound();
        $this->assertDatabaseHas('pantry_items', ['id' => $itemId, 'quantity' => '4.000']);
        $this->assertDatabaseCount('pantry_stock_movements', 1);

        $this->enable($admin, true);
        $this->actingAs($admin, 'web')->getJson('/api/pantry')->assertOk()->assertJsonCount(1, 'items');
    }

    private function item(
        User $member,
        string $name,
        string $quantity,
        string $unit,
        ?string $location = null,
        ?string $bestBefore = null,
        string $visibility = 'private',
    ): TestResponse {
        return $this->actingAs($member, 'web')->postJson('/api/pantry', [
            'name' => $name,
            'quantity' => $quantity,
            'unit' => $unit,
            'location' => $location,
            'best_before_on' => $bestBefore,
            'visibility' => $visibility,
        ]);
    }

    private function member(bool $admin = false): User
    {
        HouseholdSettings::query()->whereKey(1)->update(['setup_completed_at' => now()]);
        $this->memberSequence++;

        return User::factory()->create([
            'name' => 'Pantry Member '.$this->memberSequence,
            'email' => 'pantry-'.$this->memberSequence.'@example.test',
            'is_admin' => $admin,
            'is_active' => true,
        ]);
    }

    /** @param array{view?: bool, create?: bool, edit?: bool, manage?: bool} $capabilities */
    private function grant(User $member, array $capabilities): void
    {
        ModuleUserPermission::query()->updateOrCreate(
            ['user_id' => $member->id, 'module_key' => 'pantry'],
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
        app(ModuleManagementService::class)->setEnabled($admin, 'pantry', $enabled);
    }
}
