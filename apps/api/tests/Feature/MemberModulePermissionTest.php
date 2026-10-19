<?php

namespace Tests\Feature;

use App\Enums\ModuleCapability;
use App\Exceptions\ModuleDisabled;
use App\Http\Middleware\EnsureModuleAccess as EnsureModuleAccessForRequest;
use App\Jobs\Middleware\EnsureModuleAccess as EnsureModuleAccessForJob;
use App\Models\HouseholdSettings;
use App\Models\ModuleUserPermission;
use App\Models\User;
use App\Services\Modules\MemberModulePermissionService;
use App\Services\Modules\ModuleAccessService;
use App\Services\Modules\ModuleManagementService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Support\PrivateModuleRecord;
use Tests\TestCase;

class MemberModulePermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_manage_member_capabilities_and_changes_are_audited(): void
    {
        $admin = $this->admin();
        $member = User::factory()->create(['name' => 'Household Member', 'is_admin' => false, 'is_active' => true]);

        $this->actingAs($admin, 'web')
            ->getJson('/api/admin/members/'.$member->id.'/module-permissions')
            ->assertOk()
            ->assertJsonPath('member.id', $member->id)
            ->assertJsonPath('modules.0.key', 'tracking')
            ->assertJsonPath('modules.0.enabled', false)
            ->assertJsonPath('modules.0.capabilities.view', false)
            ->assertJsonPath('modules.0.capabilities.manage', false);

        $this->putJson('/api/admin/members/'.$member->id.'/module-permissions/tracking', [
            'capabilities' => ['view' => true, 'create' => false, 'edit' => true, 'manage' => false],
        ])->assertOk()
            ->assertJsonPath('module.capabilities.view', true)
            ->assertJsonPath('module.capabilities.edit', true);

        $this->assertDatabaseHas('module_user_permissions', [
            'user_id' => $member->id,
            'module_key' => 'tracking',
            'can_view' => true,
            'can_create' => false,
            'can_edit' => true,
            'can_manage' => false,
        ]);
        $this->assertDatabaseHas('household_audit_events', [
            'actor_user_id' => $admin->id,
            'subject_user_id' => $member->id,
            'action' => 'member.module_permissions_changed',
        ]);

        $this->putJson('/api/admin/members/'.$member->id.'/module-permissions/tracking', [
            'capabilities' => ['view' => true, 'create' => false, 'edit' => true],
        ])->assertUnprocessable();
        $this->putJson('/api/admin/members/'.$member->id.'/module-permissions/tracking', [
            'capabilities' => ['view' => true, 'create' => false, 'edit' => true, 'manage' => false, 'delete' => true],
        ])->assertUnprocessable();
        $this->putJson('/api/admin/members/'.$member->id.'/module-permissions/core', [
            'capabilities' => ['view' => true, 'create' => false, 'edit' => false, 'manage' => false],
        ])->assertConflict();

        $this->actingAs($member, 'web')
            ->getJson('/api/admin/members/'.$member->id.'/module-permissions')
            ->assertForbidden();
        $this->assertDatabaseCount('household_audit_events', 1);
    }

    public function test_api_and_job_guards_require_enabled_state_and_the_exact_member_capability(): void
    {
        $admin = $this->admin();
        $member = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $record = $this->record(owner: null, isPrivate: false);

        Route::get('/test/modules/tracking/view', function (Request $request) use ($record) {
            app(ModuleAccessService::class)->authorize($request->user('web'), 'tracking', 'view', $record);

            return response()->json(['value' => $record->value]);
        })->middleware(['auth:web', 'active-member', EnsureModuleAccessForRequest::class.':tracking,view']);

        Route::post('/test/modules/tracking/create', fn () => response()->json(['created' => true]))
            ->middleware(['auth:web', 'active-member', EnsureModuleAccessForRequest::class.':tracking,create']);

        $this->actingAs($member, 'web')->getJson('/test/modules/tracking/view')->assertNotFound();

        $this->grant($admin, $member, 'tracking', ['view' => true]);
        app(ModuleManagementService::class)->setEnabled($admin, 'tracking', true);

        $this->getJson('/test/modules/tracking/view')->assertOk()->assertJsonPath('value', 'safe test value');
        $this->postJson('/test/modules/tracking/create')->assertForbidden();

        $runs = 0;
        $job = new \stdClass;
        $middleware = new EnsureModuleAccessForJob($member->id, 'tracking', ModuleCapability::Create);
        $this->assertNull($middleware->handle($job, function () use (&$runs): string {
            $runs++;

            return 'processed';
        }));
        $this->assertSame(0, $runs);

        $this->grant($admin, $member, 'tracking', ['view' => true, 'create' => true]);
        $this->assertSame('processed', $middleware->handle($job, function () use (&$runs): string {
            $runs++;

            return 'processed';
        }));
        $this->assertSame(1, $runs);

        app(ModuleManagementService::class)->setEnabled($admin, 'tracking', false);
        $this->getJson('/test/modules/tracking/view')->assertNotFound();
        $this->assertNull($middleware->handle($job, fn () => 'should not run'));
    }

    public function test_private_records_stay_private_from_administrators_until_owner_explicitly_shares(): void
    {
        $this->createRecordTable();
        $admin = $this->admin();
        $owner = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $recipient = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $record = $this->record(owner: $owner->id, isPrivate: true);
        $this->grant($admin, $admin, 'tracking', ['view' => true]);
        $this->grant($admin, $owner, 'tracking', ['manage' => true]);
        $this->grant($admin, $recipient, 'tracking', ['view' => true]);
        app(ModuleManagementService::class)->setEnabled($admin, 'tracking', true);

        $access = app(ModuleAccessService::class);
        Route::get('/test/private/tracking-record', function (Request $request) use ($record) {
            app(ModuleAccessService::class)->authorize($request->user('web'), 'tracking', 'view', $record);

            return response()->json(['value' => $record->value]);
        })->middleware(['auth:web', 'active-member', EnsureModuleAccessForRequest::class.':tracking,view']);

        try {
            $access->authorize($owner, 'tracking', ModuleCapability::View, $record);
            $this->fail('Module manage permission must not imply view permission, even for the owner.');
        } catch (AuthorizationException) {
            $this->assertDatabaseMissing('resource_user_permissions', ['resource_id' => $record->id]);
        }

        $this->grant($admin, $owner, 'tracking', ['view' => true, 'manage' => true]);
        $access->authorize($owner, 'tracking', ModuleCapability::View, $record);

        try {
            $access->authorize($admin, 'tracking', ModuleCapability::View, $record);
            $this->fail('Administrator status must not reveal another member’s private record.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('resource_user_permissions', 0);
        }

        try {
            $access->authorize($recipient, 'tracking', ModuleCapability::View, $record);
            $this->fail('A module grant alone must not reveal a private record.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('resource_user_permissions', 0);
        }

        $this->actingAs($admin, 'web')->getJson('/test/private/tracking-record')->assertForbidden();
        $this->actingAs($owner, 'web')->getJson('/test/private/tracking-record')->assertOk();
        $this->actingAs($recipient, 'web')->getJson('/test/private/tracking-record')->assertForbidden();

        $runs = 0;
        $job = new EnsureModuleAccessForJob($recipient->id, 'tracking', ModuleCapability::View, $record);
        $this->assertNull($job->handle(new \stdClass, function () use (&$runs): string {
            $runs++;

            return 'processed';
        }));
        $this->assertSame(0, $runs);

        $access->grantPrivateResourceAccess($owner, $record, $recipient, ModuleCapability::View);
        $access->authorize($recipient, 'tracking', ModuleCapability::View, $record);
        $this->actingAs($recipient, 'web')->getJson('/test/private/tracking-record')->assertOk();
        $this->assertSame('processed', $job->handle(new \stdClass, function () use (&$runs): string {
            $runs++;

            return 'processed';
        }));
        $this->assertSame(1, $runs);
        $this->assertDatabaseHas('resource_user_permissions', [
            'resource_type' => $record->getMorphClass(),
            'resource_id' => $record->id,
            'user_id' => $recipient->id,
            'capability' => 'view',
        ]);

        $access->grantPrivateResourceAccess($owner, $record, $admin, ModuleCapability::View);
        $access->authorize($admin, 'tracking', ModuleCapability::View, $record);
        $this->actingAs($admin, 'web')->getJson('/test/private/tracking-record')->assertOk();
        $access->revokePrivateResourceAccess($owner, $record, $admin, ModuleCapability::View);

        try {
            $access->authorize($admin, 'tracking', ModuleCapability::View, $record);
            $this->fail('Revoking a resource grant must restore the privacy boundary.');
        } catch (AuthorizationException) {
            $this->getJson('/test/private/tracking-record')->assertForbidden();
            $this->assertDatabaseMissing('resource_user_permissions', [
                'resource_id' => $record->id,
                'user_id' => $admin->id,
                'capability' => 'view',
            ]);
        }

        $this->assertDatabaseHas('resource_user_permissions', [
            'resource_id' => $record->id,
            'user_id' => $recipient->id,
            'capability' => 'view',
        ]);

        $record->delete();
        $this->assertDatabaseCount('resource_user_permissions', 0);
    }

    public function test_resource_grants_do_not_override_module_grants_or_module_disablement(): void
    {
        $this->createRecordTable();
        $admin = $this->admin();
        $owner = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $recipient = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $record = $this->record(owner: $owner->id, isPrivate: true);
        $this->grant($admin, $owner, 'tracking', ['manage' => true, 'view' => true]);
        $this->grant($admin, $recipient, 'tracking', []);
        app(ModuleManagementService::class)->setEnabled($admin, 'tracking', true);

        $access = app(ModuleAccessService::class);
        $access->grantPrivateResourceAccess($owner, $record, $recipient, ModuleCapability::View);

        try {
            $access->authorize($recipient, 'tracking', ModuleCapability::View, $record);
            $this->fail('A resource grant must not replace the module capability grant.');
        } catch (AuthorizationException) {
            $this->assertDatabaseHas('resource_user_permissions', ['user_id' => $recipient->id]);
        }

        $this->grant($admin, $recipient, 'tracking', ['view' => true]);
        $access->authorize($recipient, 'tracking', ModuleCapability::View, $record);
        app(ModuleManagementService::class)->setEnabled($admin, 'tracking', false);

        $this->expectException(ModuleDisabled::class);
        $access->authorize($recipient, 'tracking', ModuleCapability::View, $record);
    }

    public function test_private_resources_without_an_owner_fail_closed(): void
    {
        $this->createRecordTable();
        $admin = $this->admin();
        $member = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $record = $this->record(owner: null, isPrivate: true);
        $this->grant($admin, $member, 'tracking', ['view' => true]);
        app(ModuleManagementService::class)->setEnabled($admin, 'tracking', true);

        $this->expectException(AuthorizationException::class);
        app(ModuleAccessService::class)->authorize($member, 'tracking', ModuleCapability::View, $record);
    }

    public function test_typed_ai_and_mcp_operations_use_the_same_member_capability_check(): void
    {
        $admin = $this->admin();
        $member = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $this->grant($admin, $member, 'ai', ['manage' => true]);
        app(ModuleManagementService::class)->setEnabled($admin, 'ai', true);
        $access = app(ModuleAccessService::class);

        try {
            $access->authorize($member, 'ai', ModuleCapability::View);
            $this->fail('AI/MCP callers must not treat manage as an implicit view grant.');
        } catch (AuthorizationException) {
            $this->assertDatabaseHas('module_user_permissions', [
                'user_id' => $member->id,
                'module_key' => 'ai',
                'can_manage' => true,
                'can_view' => false,
            ]);
        }

        $this->grant($admin, $member, 'ai', ['view' => true, 'manage' => true]);
        $access->authorize($member, 'ai', ModuleCapability::View);
        $this->assertTrue((bool) ModuleUserPermission::query()
            ->where('user_id', $member->id)
            ->where('module_key', 'ai')
            ->value('can_view'));
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

    /** @param array{view?: bool, create?: bool, edit?: bool, manage?: bool} $capabilities */
    private function grant(User $admin, User $member, string $moduleKey, array $capabilities): void
    {
        app(MemberModulePermissionService::class)->updateForMember($admin, $member->id, $moduleKey, [
            'view' => $capabilities['view'] ?? false,
            'create' => $capabilities['create'] ?? false,
            'edit' => $capabilities['edit'] ?? false,
            'manage' => $capabilities['manage'] ?? false,
        ]);
    }

    private function createRecordTable(): void
    {
        Schema::create('authorization_test_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_private')->default(true);
            $table->string('value');
        });
    }

    private function record(?int $owner, bool $isPrivate): PrivateModuleRecord
    {
        if (! Schema::hasTable('authorization_test_records')) {
            $this->createRecordTable();
        }

        return PrivateModuleRecord::query()->create([
            'owner_user_id' => $owner,
            'is_private' => $isPrivate,
            'value' => 'safe test value',
        ]);
    }
}
