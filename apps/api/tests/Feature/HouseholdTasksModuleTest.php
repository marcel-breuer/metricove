<?php

namespace Tests\Feature;

use App\Models\HouseholdSettings;
use App\Models\HouseholdTask;
use App\Models\ModuleUserPermission;
use App\Models\User;
use App\Services\Modules\ModuleManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HouseholdTasksModuleTest extends TestCase
{
    use RefreshDatabase;

    private int $memberSequence = 0;

    public function test_private_tasks_are_private_and_assignees_can_complete_their_granted_occurrence(): void
    {
        $admin = $this->member(true);
        $owner = $this->member();
        $assignee = $this->member();
        $observer = $this->member();
        $this->enable($admin, true);

        $created = $this->actingAs($owner, 'web')->postJson('/api/tasks', [
            'title' => 'Clean the garage', 'description' => 'Private weekend job', 'priority' => 'high',
            'assignee_id' => $assignee->id, 'due_at' => '2026-10-31T09:00:00+01:00', 'timezone' => 'Europe/Berlin',
            'recurrence_frequency' => 'monthly', 'recurrence_interval' => 1,
        ])->assertCreated()->assertJsonPath('task.visibility', 'private')
            ->assertJsonPath('task.assignee.id', $assignee->id)->assertJsonPath('task.can_edit', true);
        $taskId = (int) $created->json('task.id');

        $this->actingAs($admin, 'web')->getJson('/api/tasks')->assertOk()->assertJsonCount(0, 'tasks');
        $this->actingAs($admin, 'web')->getJson("/api/tasks/{$taskId}/history")->assertNotFound();
        $this->actingAs($observer, 'web')->getJson('/api/tasks')->assertOk()->assertJsonCount(0, 'tasks');
        $this->actingAs($observer, 'web')->getJson("/api/tasks/{$taskId}/history")->assertNotFound();
        $this->actingAs($assignee, 'web')->getJson('/api/tasks')
            ->assertOk()->assertJsonCount(1, 'tasks')->assertJsonPath('tasks.0.title', 'Clean the garage')
            ->assertJsonPath('tasks.0.can_complete', true)->assertJsonPath('tasks.0.can_edit', false);

        $this->actingAs($assignee, 'web')->patchJson("/api/tasks/{$taskId}", [
            'expected_version' => 1, 'title' => 'Unauthorized edit',
        ])->assertForbidden();

        $this->actingAs($assignee, 'web')->postJson("/api/tasks/{$taskId}/complete", ['expected_version' => 1])
            ->assertOk()->assertJsonPath('task.occurrence_number', 2)->assertJsonPath('task.is_completed', false)
            ->assertJsonPath('task.due_at', '2026-11-30T08:00:00.000000Z');
        $this->actingAs($assignee, 'web')->postJson("/api/tasks/{$taskId}/complete", ['expected_version' => 1])->assertConflict();
        $this->actingAs($assignee, 'web')->getJson("/api/tasks/{$taskId}/history")
            ->assertOk()->assertJsonCount(1, 'completions')
            ->assertJsonPath('completions.0.occurrence_number', 1)
            ->assertJsonPath('completions.0.completed_by', $assignee->name);
        $this->assertDatabaseHas('household_tasks', [
            'id' => $taskId, 'title' => 'Clean the garage', 'occurrence_number' => 2, 'version' => 2,
        ]);
    }

    public function test_shared_tasks_are_household_visible_but_only_assignees_or_admins_can_complete(): void
    {
        $admin = $this->member(true);
        $successor = $this->member(true);
        $assignee = $this->member();
        $observer = $this->member();
        $this->enable($admin, true);

        $taskId = (int) $this->actingAs($admin, 'web')->postJson('/api/tasks', [
            'title' => 'Put out recycling', 'visibility' => 'household', 'assignee_id' => $assignee->id,
            'due_at' => '2026-10-24T20:00:00Z', 'timezone' => 'UTC', 'priority' => 'medium',
        ])->assertCreated()->assertJsonPath('task.visibility', 'household')->json('task.id');

        $this->actingAs($observer, 'web')->getJson('/api/tasks')->assertOk()
            ->assertJsonCount(1, 'tasks')->assertJsonPath('tasks.0.assignee.id', $assignee->id)
            ->assertJsonPath('tasks.0.can_complete', false)->assertJsonPath('tasks.0.can_edit', false);
        $this->actingAs($observer, 'web')->postJson("/api/tasks/{$taskId}/complete", ['expected_version' => 1])->assertForbidden();
        $this->actingAs($assignee, 'web')->postJson("/api/tasks/{$taskId}/complete", ['expected_version' => 1])
            ->assertOk()->assertJsonPath('task.is_completed', true);
        $this->actingAs($admin, 'web')->patchJson("/api/tasks/{$taskId}", [
            'expected_version' => 2, 'title' => 'Recycling collected',
        ])->assertOk()->assertJsonPath('task.title', 'Recycling collected');

        $otherTaskId = (int) $this->actingAs($admin, 'web')->postJson('/api/tasks', [
            'title' => 'Sweep the entrance', 'visibility' => 'household', 'priority' => 'low',
        ])->assertCreated()->json('task.id');
        $staleAdmin = clone $admin;
        $admin->forceFill(['is_admin' => false])->save();
        $this->actingAs($staleAdmin, 'web')->postJson("/api/tasks/{$otherTaskId}/complete", ['expected_version' => 1])->assertForbidden();
        $this->actingAs($successor, 'web')->postJson("/api/tasks/{$otherTaskId}/complete", ['expected_version' => 1])->assertOk();
    }

    public function test_only_an_active_admin_with_manage_can_create_shared_tasks_and_assignees_need_view_and_edit(): void
    {
        $admin = $this->member(true);
        $member = $this->member();
        $restricted = $this->member();
        $this->enable($admin, true);
        $this->grant($restricted, ['view' => true]);

        $this->actingAs($member, 'web')->postJson('/api/tasks', [
            'title' => 'Shared task', 'visibility' => 'household', 'priority' => 'low',
        ])->assertForbidden();
        $this->actingAs($admin, 'web')->postJson('/api/tasks', [
            'title' => 'Assign to restricted member', 'visibility' => 'household', 'assignee_id' => $restricted->id,
            'priority' => 'low',
        ])->assertUnprocessable();
        $this->assertDatabaseCount('household_tasks', 0);
    }

    public function test_non_recurring_task_rejects_interval_without_frequency(): void
    {
        $admin = $this->member(true);
        $this->enable($admin, true);

        $taskId = (int) $this->actingAs($admin, 'web')->postJson('/api/tasks', [
            'title' => 'Replace the batteries', 'priority' => 'medium',
        ])->assertCreated()->json('task.id');

        $this->actingAs($admin, 'web')->patchJson("/api/tasks/{$taskId}", [
            'expected_version' => 1,
            'recurrence_interval' => 2,
        ])->assertUnprocessable()->assertJsonValidationErrors('recurrence_interval');

        $this->assertDatabaseHas('household_tasks', [
            'id' => $taskId,
            'recurrence_frequency' => null,
            'recurrence_interval' => 1,
            'version' => 1,
        ]);
    }

    public function test_monthly_and_yearly_recurrence_retain_the_original_calendar_anchor(): void
    {
        $admin = $this->member(true);
        $this->enable($admin, true);

        $monthlyId = (int) $this->actingAs($admin, 'web')->postJson('/api/tasks', [
            'title' => 'Month end task', 'priority' => 'medium', 'due_at' => '2026-01-31T09:00:00Z',
            'timezone' => 'UTC', 'recurrence_frequency' => 'monthly', 'recurrence_interval' => 1,
        ])->assertCreated()->json('task.id');
        $this->actingAs($admin, 'web')->postJson("/api/tasks/{$monthlyId}/complete", ['expected_version' => 1])
            ->assertOk()->assertJsonPath('task.due_at', '2026-02-28T09:00:00.000000Z');
        $this->actingAs($admin, 'web')->patchJson("/api/tasks/{$monthlyId}", [
            'expected_version' => 2, 'title' => 'Month end task updated',
            'due_at' => '2026-02-28T09:00:00Z', 'timezone' => 'UTC',
            'recurrence_frequency' => 'monthly', 'recurrence_interval' => 1,
        ])->assertOk();
        $this->actingAs($admin, 'web')->postJson("/api/tasks/{$monthlyId}/complete", ['expected_version' => 3])
            ->assertOk()->assertJsonPath('task.due_at', '2026-03-31T09:00:00.000000Z');

        $yearlyId = (int) $this->actingAs($admin, 'web')->postJson('/api/tasks', [
            'title' => 'Leap day task', 'priority' => 'medium', 'due_at' => '2024-02-29T09:00:00Z',
            'timezone' => 'UTC', 'recurrence_frequency' => 'yearly', 'recurrence_interval' => 1,
        ])->assertCreated()->json('task.id');
        foreach ([
            '2025-02-28T09:00:00.000000Z',
            '2026-02-28T09:00:00.000000Z',
            '2027-02-28T09:00:00.000000Z',
            '2028-02-29T09:00:00.000000Z',
        ] as $index => $expectedDueAt) {
            $this->actingAs($admin, 'web')->postJson("/api/tasks/{$yearlyId}/complete", ['expected_version' => $index + 1])
                ->assertOk()->assertJsonPath('task.due_at', $expectedDueAt);
        }
    }

    public function test_disabling_tasks_preserves_tasks_and_completion_history(): void
    {
        $admin = $this->member(true);
        $this->enable($admin, true);
        $taskId = (int) $this->actingAs($admin, 'web')->postJson('/api/tasks', [
            'title' => 'Replace the water filter', 'priority' => 'medium',
        ])->assertCreated()->json('task.id');
        $this->actingAs($admin, 'web')->postJson("/api/tasks/{$taskId}/complete", ['expected_version' => 1])->assertOk();
        $this->enable($admin, false);

        $this->actingAs($admin, 'web')->getJson('/api/tasks')->assertNotFound();
        $this->actingAs($admin, 'web')->postJson("/api/tasks/{$taskId}/complete", ['expected_version' => 2])->assertNotFound();
        $this->assertDatabaseHas('household_tasks', ['id' => $taskId, 'is_completed' => true]);
        $this->assertDatabaseHas('household_task_completions', ['task_id' => $taskId, 'occurrence_number' => 1]);

        $this->enable($admin, true);
        $this->actingAs($admin, 'web')->getJson('/api/tasks?status=completed')->assertOk()
            ->assertJsonCount(1, 'tasks')->assertJsonPath('tasks.0.title', 'Replace the water filter');
    }

    public function test_filters_sorting_and_result_limit_apply_to_visible_tasks(): void
    {
        $admin = $this->member(true);
        $assignee = $this->member();
        $otherAssignee = $this->member();
        $this->enable($admin, true);

        $this->actingAs($admin, 'web')->postJson('/api/tasks', [
            'title' => 'High overdue', 'visibility' => 'household', 'assignee_id' => $assignee->id,
            'due_at' => now()->subDay()->toIso8601String(), 'timezone' => 'UTC', 'priority' => 'high',
        ])->assertCreated()->json('task.id');
        $this->actingAs($admin, 'web')->postJson('/api/tasks', [
            'title' => 'Low no due', 'visibility' => 'household', 'priority' => 'low',
        ])->assertCreated();
        $this->actingAs($admin, 'web')->postJson('/api/tasks', [
            'title' => 'Medium upcoming', 'visibility' => 'household', 'assignee_id' => $otherAssignee->id,
            'due_at' => now()->addDay()->toIso8601String(), 'timezone' => 'UTC', 'priority' => 'medium',
        ])->assertCreated();
        $completedId = (int) $this->actingAs($admin, 'web')->postJson('/api/tasks', [
            'title' => 'Complete', 'visibility' => 'household', 'priority' => 'low',
        ])->assertCreated()->json('task.id');
        $this->actingAs($admin, 'web')->postJson("/api/tasks/{$completedId}/complete", ['expected_version' => 1])->assertOk();

        $this->actingAs($admin, 'web')->getJson('/api/tasks?status=open')->assertOk()->assertJsonCount(3, 'tasks');
        $this->getJson('/api/tasks?status=completed')->assertOk()->assertJsonCount(1, 'tasks')->assertJsonPath('tasks.0.title', 'Complete');
        $this->getJson('/api/tasks?due=overdue')->assertOk()->assertJsonCount(1, 'tasks')->assertJsonPath('tasks.0.title', 'High overdue');
        $this->getJson('/api/tasks?due=upcoming')->assertOk()->assertJsonCount(1, 'tasks')->assertJsonPath('tasks.0.title', 'Medium upcoming');
        $this->getJson('/api/tasks?due=none')->assertOk()->assertJsonCount(1, 'tasks')->assertJsonPath('tasks.0.title', 'Low no due');
        $this->getJson('/api/tasks?priority=high')->assertOk()->assertJsonCount(1, 'tasks')->assertJsonPath('tasks.0.title', 'High overdue');
        $this->getJson('/api/tasks?assignee_id='.$assignee->id)->assertOk()->assertJsonCount(1, 'tasks')->assertJsonPath('tasks.0.title', 'High overdue');
        $this->getJson('/api/tasks?sort=priority')->assertOk()->assertJsonPath('tasks.0.title', 'High overdue')
            ->assertJsonPath('tasks.1.title', 'Medium upcoming')->assertJsonPath('tasks.2.title', 'Low no due');

        for ($index = 1; $index <= 201; $index++) {
            HouseholdTask::query()->create([
                'created_by_user_id' => $admin->id,
                'is_private' => false,
                'title' => 'Bounded task '.$index,
                'timezone' => 'UTC',
                'priority' => 'medium',
                'is_completed' => false,
                'occurrence_number' => 1,
                'version' => 1,
            ]);
        }
        $this->getJson('/api/tasks?status=all')->assertOk()->assertJsonCount(200, 'tasks');
    }

    private function member(bool $admin = false): User
    {
        HouseholdSettings::query()->whereKey(1)->update(['setup_completed_at' => now()]);
        $this->memberSequence++;

        return User::factory()->create([
            'name' => 'Task Member '.$this->memberSequence,
            'email' => 'task-'.$this->memberSequence.'@example.test',
            'is_admin' => $admin,
            'is_active' => true,
        ]);
    }

    /** @param array{view?: bool, create?: bool, edit?: bool, manage?: bool} $capabilities */
    private function grant(User $member, array $capabilities): void
    {
        ModuleUserPermission::query()->updateOrCreate(
            ['user_id' => $member->id, 'module_key' => 'tasks'],
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
        app(ModuleManagementService::class)->setEnabled($admin, 'tasks', $enabled);
    }
}
