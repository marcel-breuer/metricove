<?php

namespace Tests\Feature;

use App\Models\HouseholdAuditEvent;
use App\Models\HouseholdSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MemberManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_admin_can_list_members_change_access_and_review_audit_history(): void
    {
        $admin = $this->admin();
        $password = Str::random(32);
        $member = User::factory()->create([
            'name' => 'Household Member',
            'email' => 'member@example.test',
            'password' => $password,
            'is_admin' => false,
            'is_active' => true,
        ]);
        $removed = User::factory()->create([
            'email' => 'removed@example.test',
            'is_admin' => false,
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'web')
            ->getJson('/api/admin/members')
            ->assertOk()
            ->assertJsonCount(3, 'members')
            ->assertJsonPath('members.1.id', $member->id)
            ->assertJsonPath('members.1.is_admin', false)
            ->assertJsonPath('members.1.is_active', true)
            ->assertJsonMissingPath('members.1.password')
            ->assertJsonMissingPath('members.1.remember_token');

        $this->patchJson('/api/admin/members/'.$member->id, ['is_admin' => true])
            ->assertOk()
            ->assertJsonPath('member.is_admin', true);
        $this->patchJson('/api/admin/members/'.$member->id, ['is_admin' => false])
            ->assertOk()
            ->assertJsonPath('member.is_admin', false);
        $this->patchJson('/api/admin/members/'.$member->id, ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('member.is_active', false);

        $this->postJson('/api/auth/login', [
            'email' => $member->email,
            'password' => $password,
        ])->assertUnprocessable();

        $this->patchJson('/api/admin/members/'.$member->id, ['is_active' => true])
            ->assertOk()
            ->assertJsonPath('member.is_active', true);

        $this->deleteJson('/api/admin/members/'.$removed->id)
            ->assertOk()
            ->assertJsonPath('message', 'Member removed.');

        $this->assertDatabaseMissing('users', ['id' => $removed->id]);
        $this->assertSame(5, HouseholdAuditEvent::query()->count());

        $auditResponse = $this->getJson('/api/admin/member-audit')
            ->assertOk()
            ->assertJsonCount(5, 'events')
            ->assertJsonPath('events.0.action', 'member.removed')
            ->assertJsonPath('events.0.actor_user_id', $admin->id)
            ->assertJsonPath('events.0.subject_user_id', $removed->id);

        $this->assertStringNotContainsString('member@example.test', $auditResponse->getContent());
        $this->assertStringNotContainsString($password, $auditResponse->getContent());
        $this->assertDatabaseHas('household_audit_events', [
            'actor_user_id' => $admin->id,
            'subject_user_id' => $member->id,
            'action' => 'member.promoted',
        ]);
        $this->assertDatabaseHas('household_audit_events', [
            'actor_user_id' => $admin->id,
            'subject_user_id' => $member->id,
            'action' => 'member.demoted',
        ]);
        $this->assertDatabaseHas('household_audit_events', [
            'actor_user_id' => $admin->id,
            'subject_user_id' => $member->id,
            'action' => 'member.suspended',
        ]);
        $this->assertDatabaseHas('household_audit_events', [
            'actor_user_id' => $admin->id,
            'subject_user_id' => $member->id,
            'action' => 'member.activated',
        ]);
    }

    public function test_only_active_administrators_can_read_or_change_members(): void
    {
        $member = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $inactiveAdmin = User::factory()->create(['is_admin' => true, 'is_active' => false]);

        $this->getJson('/api/admin/members')->assertUnauthorized();

        $this->actingAs($member, 'web')
            ->getJson('/api/admin/members')
            ->assertForbidden();

        $this->patchJson('/api/admin/members/'.$member->id, ['is_admin' => true])
            ->assertForbidden();

        $this->actingAs($inactiveAdmin, 'web')
            ->getJson('/api/admin/member-audit')
            ->assertUnauthorized();

        $this->assertDatabaseCount('household_audit_events', 0);
    }

    public function test_last_active_administrator_cannot_be_demoted_suspended_or_removed(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'web')
            ->patchJson('/api/admin/members/'.$admin->id, ['is_admin' => false])
            ->assertConflict();

        $this->patchJson('/api/admin/members/'.$admin->id, ['is_active' => false])
            ->assertConflict();

        $this->patchJson('/api/admin/members/'.$admin->id, [
            'is_admin' => false,
            'is_active' => false,
        ])->assertConflict();

        $this->deleteJson('/api/admin/members/'.$admin->id)
            ->assertConflict();

        $this->assertDatabaseHas('users', [
            'id' => $admin->id,
            'is_admin' => true,
            'is_active' => true,
        ]);
        $this->assertDatabaseCount('household_audit_events', 0);
    }

    public function test_suspended_member_cannot_use_authenticated_api_routes(): void
    {
        $member = User::factory()->create(['is_admin' => false, 'is_active' => false]);

        $this->actingAs($member, 'web')
            ->getJson('/api/profile')
            ->assertUnauthorized();

        $this->assertGuest('web');
    }

    public function test_member_update_rejects_profile_identity_and_empty_changes(): void
    {
        $admin = $this->admin();
        $member = User::factory()->create([
            'name' => 'Original Name',
            'email' => 'original@example.test',
            'is_admin' => false,
        ]);

        $this->actingAs($admin, 'web')
            ->patchJson('/api/admin/members/'.$member->id, [
                'is_admin' => true,
                'name' => 'Changed Name',
                'email' => 'changed@example.test',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email']);

        $this->patchJson('/api/admin/members/'.$member->id, [])->assertUnprocessable();

        $this->assertDatabaseHas('users', [
            'id' => $member->id,
            'name' => 'Original Name',
            'email' => 'original@example.test',
            'is_admin' => false,
        ]);
        $this->assertDatabaseCount('household_audit_events', 0);
    }

    public function test_console_recovery_reactivates_an_existing_member_as_an_admin(): void
    {
        $disabledAdmin = User::factory()->create(['is_admin' => true, 'is_active' => false]);
        $member = User::factory()->create([
            'email' => 'recovery@example.test',
            'is_admin' => false,
            'is_active' => false,
        ]);

        $this->artisan('metricove:recover-admin', ['userId' => $member->id])
            ->expectsOutput("Administrator access restored for member {$member->id}.")
            ->assertSuccessful();

        $this->assertDatabaseHas('users', [
            'id' => $member->id,
            'is_admin' => true,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('household_audit_events', [
            'actor_user_id' => null,
            'subject_user_id' => $member->id,
            'action' => 'admin.recovered',
        ]);
        $this->assertDatabaseHas('users', ['id' => $disabledAdmin->id, 'is_admin' => true, 'is_active' => false]);
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
