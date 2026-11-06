<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureModuleEnabled as EnsureModuleEnabledForRequest;
use App\Jobs\Middleware\EnsureModuleEnabled as EnsureModuleEnabledForJob;
use App\Models\HouseholdSettings;
use App\Models\ModuleUserPermission;
use App\Models\User;
use App\Services\Modules\ModuleManagementService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ModuleActivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_enable_disable_and_reenable_modules_without_deleting_data(): void
    {
        $admin = $this->admin();
        Schema::create('tracking_records', function (Blueprint $table): void {
            $table->id();
            $table->string('value');
        });
        DB::table('tracking_records')->insert(['id' => 1, 'value' => 'preserved']);

        $this->actingAs($admin, 'web')
            ->getJson('/api/admin/modules')
            ->assertOk()
            ->assertJsonCount(14, 'modules')
            ->assertJsonPath('modules.0.key', 'core')
            ->assertJsonPath('modules.0.enabled', true)
            ->assertJsonPath('modules.0.can_disable', false)
            ->assertJsonPath('modules.1.key', 'tracking')
            ->assertJsonPath('modules.1.enabled', false)
            ->assertJsonPath('modules.9.key', 'tasks')
            ->assertJsonPath('modules.13.key', 'receipts');

        $this->getJson('/api/modules')
            ->assertOk()
            ->assertJsonPath('enabled_modules', ['core']);

        $this->patchJson('/api/admin/modules/tracking', ['enabled' => true])
            ->assertOk()
            ->assertJsonPath('module.enabled', true);

        $this->getJson('/api/modules')
            ->assertOk()
            ->assertJsonPath('enabled_modules', ['core', 'tracking']);

        $this->patchJson('/api/admin/modules/tracking', ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('message', 'Module disabled. Existing data is preserved.')
            ->assertJsonPath('module.enabled', false);

        $this->assertDatabaseHas('tracking_records', ['id' => 1, 'value' => 'preserved']);
        $this->assertDatabaseHas('module_states', ['module_key' => 'tracking', 'is_enabled' => false]);
        $this->getJson('/api/modules')->assertJsonPath('enabled_modules', ['core']);

        $this->patchJson('/api/admin/modules/tracking', ['enabled' => true])
            ->assertOk()
            ->assertJsonPath('module.enabled', true);
        $this->assertDatabaseHas('tracking_records', ['id' => 1, 'value' => 'preserved']);
    }

    public function test_enabling_grants_every_capability_to_active_members_and_reenabling_restores_them(): void
    {
        $admin = $this->admin();
        $member = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $suspendedMember = User::factory()->create(['is_admin' => false, 'is_active' => false]);

        $this->assertDatabaseMissing('module_user_permissions', ['module_key' => 'tracking']);
        app(ModuleManagementService::class)->setEnabled($admin, 'tracking', true);

        $this->assertDatabaseHas('module_user_permissions', [
            'user_id' => $admin->id,
            'module_key' => 'tracking',
            'can_view' => true,
            'can_create' => true,
            'can_edit' => true,
            'can_manage' => true,
        ]);
        $this->assertDatabaseHas('module_user_permissions', [
            'user_id' => $member->id,
            'module_key' => 'tracking',
            'can_view' => true,
            'can_create' => true,
            'can_edit' => true,
            'can_manage' => true,
        ]);
        $this->assertDatabaseMissing('module_user_permissions', [
            'user_id' => $suspendedMember->id,
            'module_key' => 'tracking',
        ]);
        $this->assertDatabaseCount('household_audit_events', 2);

        ModuleUserPermission::query()->where('user_id', $member->id)->where('module_key', 'tracking')->update([
            'can_view' => true,
            'can_create' => false,
            'can_edit' => false,
            'can_manage' => false,
        ]);
        app(ModuleManagementService::class)->setEnabled($admin, 'tracking', true);
        $this->assertDatabaseHas('module_user_permissions', [
            'user_id' => $member->id,
            'module_key' => 'tracking',
            'can_create' => false,
            'can_edit' => false,
            'can_manage' => false,
        ]);

        app(ModuleManagementService::class)->setEnabled($admin, 'tracking', false);
        $this->assertDatabaseHas('module_user_permissions', [
            'user_id' => $member->id,
            'module_key' => 'tracking',
            'can_create' => false,
            'can_edit' => false,
            'can_manage' => false,
        ]);
        app(ModuleManagementService::class)->setEnabled($admin, 'tracking', true);

        $this->assertDatabaseHas('module_user_permissions', [
            'user_id' => $member->id,
            'module_key' => 'tracking',
            'can_view' => true,
            'can_create' => true,
            'can_edit' => true,
            'can_manage' => true,
        ]);
        $this->assertDatabaseCount('household_audit_events', 3);
    }

    public function test_core_cannot_be_disabled_and_members_cannot_manage_modules(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'web')
            ->patchJson('/api/admin/modules/core', ['enabled' => false])
            ->assertConflict()
            ->assertJsonPath('reason', 'core_always_enabled')
            ->assertJsonPath('module_keys', ['core']);

        $this->assertDatabaseMissing('module_states', ['module_key' => 'core']);

        $member = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $this->actingAs($member, 'web')->getJson('/api/admin/modules')->assertForbidden();
        $this->patchJson('/api/admin/modules/tracking', ['enabled' => true])->assertForbidden();
        $this->assertDatabaseCount('module_states', 0);
    }

    public function test_module_navigation_discovery_only_returns_enabled_modules_with_member_capabilities(): void
    {
        $admin = $this->admin();
        $member = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $states = app(ModuleManagementService::class);
        $states->setEnabled($admin, 'tracking', true);
        $states->setEnabled($admin, 'utilities', true);

        ModuleUserPermission::query()->updateOrCreate(
            ['user_id' => $member->id, 'module_key' => 'tracking'],
            ['can_view' => false, 'can_create' => true, 'can_edit' => false, 'can_manage' => false],
        );
        ModuleUserPermission::query()->updateOrCreate(
            ['user_id' => $member->id, 'module_key' => 'utilities'],
            ['can_view' => true, 'can_create' => false, 'can_edit' => false, 'can_manage' => false],
        );
        ModuleUserPermission::query()->updateOrCreate(
            ['user_id' => $member->id, 'module_key' => 'finance'],
            ['can_view' => true, 'can_create' => false, 'can_edit' => false, 'can_manage' => false],
        );

        $this->actingAs($member, 'web')
            ->getJson('/api/modules')
            ->assertOk()
            ->assertJsonPath('enabled_modules', ['core', 'tracking', 'utilities'])
            ->assertJsonPath('available_modules.0.key', 'tracking')
            ->assertJsonPath('available_modules.0.capabilities.create', true)
            ->assertJsonPath('available_modules.1.key', 'utilities')
            ->assertJsonMissingPath('available_modules.2');

        $states->setEnabled($admin, 'utilities', false);

        $this->getJson('/api/modules')
            ->assertOk()
            ->assertJsonPath('enabled_modules', ['core', 'tracking'])
            ->assertJsonPath('available_modules.0.key', 'tracking')
            ->assertJsonMissingPath('available_modules.1');
    }

    public function test_dependency_manifest_blocks_enable_and_dependency_disable_with_explanation(): void
    {
        $admin = $this->admin();
        config(['modules.catalog.test_consumer' => [
            'label' => 'Test Consumer',
            'description' => 'A test-only module with an optional dependency.',
            'version' => '1.0.0',
            'dependencies' => ['core', 'tracking'],
            'always_enabled' => false,
        ]]);

        $this->actingAs($admin, 'web')
            ->getJson('/api/admin/modules')
            ->assertOk()
            ->assertJsonPath('modules.14.key', 'test_consumer')
            ->assertJsonPath('modules.14.missing_dependencies', ['tracking']);

        $this->patchJson('/api/admin/modules/test_consumer', ['enabled' => true])
            ->assertUnprocessable()
            ->assertJsonPath('reason', 'dependencies_disabled')
            ->assertJsonPath('module_keys', ['tracking']);

        $this->patchJson('/api/admin/modules/tracking', ['enabled' => true])->assertOk();
        $this->patchJson('/api/admin/modules/test_consumer', ['enabled' => true])->assertOk();

        $this->patchJson('/api/admin/modules/tracking', ['enabled' => false])
            ->assertConflict()
            ->assertJsonPath('reason', 'dependents_enabled')
            ->assertJsonPath('module_keys', ['test_consumer']);

        $this->assertDatabaseHas('module_states', ['module_key' => 'tracking', 'is_enabled' => true]);
        $this->assertDatabaseHas('module_states', ['module_key' => 'test_consumer', 'is_enabled' => true]);
    }

    public function test_unknown_module_is_not_manageable_and_stale_admin_is_rechecked_in_transaction(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'web')
            ->patchJson('/api/admin/modules/not_registered', ['enabled' => true])
            ->assertNotFound();

        $admin->forceFill(['is_admin' => false])->save();

        try {
            app(ModuleManagementService::class)->setEnabled($admin, 'tracking', true);
            $this->fail('A demoted administrator must not be able to enable modules.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('module_states', 0);
        }
    }

    public function test_http_queue_and_typed_tool_guards_read_current_module_state(): void
    {
        $admin = $this->admin();
        Route::get('/test/modules/tracking', fn () => response()->json(['ok' => true]))
            ->middleware(EnsureModuleEnabledForRequest::class.':tracking');

        $this->getJson('/test/modules/tracking')->assertNotFound();

        $executions = 0;
        $jobMiddleware = new EnsureModuleEnabledForJob('tracking');
        $jobMiddleware->handle(new \stdClass, function (object $job) use (&$executions): string {
            $executions++;

            return 'processed';
        });
        $this->assertSame(0, $executions);

        $this->assertArrayNotHasKey('ai', config('modules.catalog'));

        app(ModuleManagementService::class)->setEnabled($admin, 'tracking', true);
        $this->getJson('/test/modules/tracking')->assertOk()->assertJsonPath('ok', true);
        $this->assertSame('processed', $jobMiddleware->handle(new \stdClass, function (object $job) use (&$executions): string {
            $executions++;

            return 'processed';
        }));
        $this->assertSame(1, $executions);

    }

    private function admin(): User
    {
        HouseholdSettings::query()->whereKey(1)->update(['setup_completed_at' => now()]);

        return User::factory()->create([
            'name' => 'Household Admin',
            'email' => 'admin@example.test',
            'is_admin' => true,
            'is_active' => true,
        ]);
    }
}
