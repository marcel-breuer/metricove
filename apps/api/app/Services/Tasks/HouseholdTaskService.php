<?php

namespace App\Services\Tasks;

use App\Enums\ModuleCapability;
use App\Exceptions\ModuleDisabled;
use App\Exceptions\ModuleNotFound;
use App\Models\HouseholdReminder;
use App\Models\HouseholdSettings;
use App\Models\HouseholdTask;
use App\Models\HouseholdTaskChecklistItem;
use App\Models\HouseholdTaskChecklistOccurrence;
use App\Models\HouseholdTaskCompletion;
use App\Models\ModuleUserPermission;
use App\Models\User;
use App\Services\Modules\ModuleAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class HouseholdTaskService
{
    private const MODULE = 'tasks';

    public function __construct(private readonly ModuleAccessService $access) {}

    /** @param array{status?: string, assignee_id?: int|null, priority?: string, due?: string, sort?: string, direction?: string} $filters
     * @return list<array<string, mixed>>
     */
    public function index(User $actor, array $filters): array
    {
        $this->access->authorize($actor, self::MODULE, ModuleCapability::View);
        $canManageShared = $this->canManageShared($actor);
        $query = $this->visibleTasks($actor, [ModuleCapability::View], HouseholdTask::query())
            ->with(['assignee', 'completions.completedBy', 'checklistItems.currentOccurrence.completedBy']);

        $status = $filters['status'] ?? 'open';
        if ($status === 'open') {
            $query->where('household_tasks.is_completed', false);
        } elseif ($status === 'completed') {
            $query->where('household_tasks.is_completed', true);
        }
        if (isset($filters['assignee_id'])) {
            $query->where('household_tasks.assignee_user_id', $filters['assignee_id']);
        }
        if (isset($filters['priority'])) {
            $query->where('household_tasks.priority', $filters['priority']);
        }
        if (($filters['due'] ?? null) === 'none') {
            $query->whereNull('household_tasks.due_at');
        } elseif (($filters['due'] ?? null) === 'overdue') {
            $query->where('household_tasks.due_at', '<', now())->where('household_tasks.is_completed', false);
        } elseif (($filters['due'] ?? null) === 'upcoming') {
            $query->where('household_tasks.due_at', '>=', now())->where('household_tasks.is_completed', false);
        }

        $direction = $filters['direction'] ?? 'asc';
        switch ($filters['sort'] ?? 'due_at') {
            case 'priority':
                $query->orderByRaw("CASE household_tasks.priority WHEN 'high' THEN 0 WHEN 'medium' THEN 1 ELSE 2 END {$direction}");
                break;
            case 'assignee':
                $query->leftJoin('users as task_assignees', 'task_assignees.id', '=', 'household_tasks.assignee_user_id')
                    ->select('household_tasks.*')->orderBy('task_assignees.name', $direction);
                break;
            case 'created_at':
                $query->orderBy('household_tasks.created_at', $direction);
                break;
            default:
                $query->orderByRaw('CASE WHEN household_tasks.due_at IS NULL THEN 1 ELSE 0 END ASC')
                    ->orderBy('household_tasks.due_at', $direction);
        }

        return $query->orderBy('household_tasks.id')->limit(200)->get()
            ->map(fn (HouseholdTask $task): array => $this->present($actor, $task, $canManageShared))->all();
    }

    /** @return list<array{id: int, name: string}> */
    public function assignees(User $actor): array
    {
        $this->access->authorize($actor, self::MODULE, ModuleCapability::Manage);

        return User::query()->join('module_user_permissions', 'module_user_permissions.user_id', '=', 'users.id')
            ->where('users.is_active', true)->where('module_user_permissions.module_key', self::MODULE)
            ->where('module_user_permissions.can_view', true)->where('module_user_permissions.can_edit', true)
            ->orderBy('users.name')->orderBy('users.id')->limit(200)
            ->get(['users.id', 'users.name'])
            ->map(fn (User $member): array => ['id' => (int) $member->id, 'name' => $member->name])->all();
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(User $actor, array $data): array
    {
        $private = $data['visibility'] === 'private';
        $frequency = $data['recurrence_frequency'] ?? null;
        $dueAt = $this->dueAt($data['due_at'] ?? null);
        $this->assertRecurringTaskHasDueDate($frequency, $dueAt);

        $task = DB::transaction(function () use ($actor, $data, $private, $frequency, $dueAt): HouseholdTask {
            if ($private) {
                $this->access->authorize($actor, self::MODULE, ModuleCapability::Create);
            } else {
                $this->authorizeSharedManagement($actor);
            }

            $assignee = $this->resolveAssignee($data['assignee_id'] ?? null);
            $task = HouseholdTask::query()->create([
                'owner_user_id' => $private ? $actor->id : null,
                'created_by_user_id' => $actor->id,
                'assignee_user_id' => $assignee?->id,
                'is_private' => $private,
                'title' => trim($data['title']),
                'description' => $this->nullableTrim($data['description'] ?? null),
                'due_at' => $dueAt,
                'timezone' => $data['timezone'],
                'priority' => $data['priority'],
                'recurrence_frequency' => $frequency,
                'recurrence_interval' => $frequency === null ? 1 : (int) $data['recurrence_interval'],
                'recurrence_anchor_date' => $frequency === null ? null : $dueAt->setTimezone($data['timezone'])->toDateString(),
                'is_completed' => false,
                'occurrence_number' => 1,
                'version' => 1,
            ]);

            if ($private && $assignee !== null && $assignee->id !== $actor->id) {
                $this->sharePrivateAssignment($actor, $task, $assignee);
            }

            return $task;
        }, 3);

        return $this->present($actor, $task->fresh(['assignee', 'completions.completedBy']));
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(User $actor, int $taskId, array $data): array
    {
        $task = DB::transaction(function () use ($actor, $taskId, $data): HouseholdTask {
            $task = $this->findForEdit($actor, $taskId);
            $this->authorizeTaskUpdate($actor, $task);
            $this->access->authorize($actor, self::MODULE, ModuleCapability::View, $task);
            $this->assertVersion($task, (int) $data['expected_version']);

            $frequency = array_key_exists('recurrence_frequency', $data)
                ? $data['recurrence_frequency']
                : $task->recurrence_frequency;
            $dueAt = array_key_exists('due_at', $data) ? $this->dueAt($data['due_at']) : $task->due_at;
            if ($frequency === null && isset($data['recurrence_interval']) && (int) $data['recurrence_interval'] !== 1) {
                throw ValidationException::withMessages([
                    'recurrence_interval' => 'A recurrence interval greater than 1 requires a recurrence frequency.',
                ]);
            }
            $this->assertRecurringTaskHasDueDate($frequency, $dueAt);
            $timezone = $data['timezone'] ?? $task->timezone;
            $previousDueAt = $task->due_at;
            $previousTimezone = $task->timezone;
            $dueDateChanged = $dueAt === null
                ? $task->due_at !== null
                : $task->due_at === null || ! $dueAt->equalTo($task->due_at);
            $scheduleChanged = $frequency !== $task->recurrence_frequency
                || $dueDateChanged
                || $timezone !== $task->timezone;
            $oldAssigneeId = $task->assignee_user_id === null ? null : (int) $task->assignee_user_id;
            $assignee = array_key_exists('assignee_id', $data)
                ? $this->resolveAssignee($data['assignee_id'])
                : $task->assignee;
            $newAssigneeId = $assignee?->id;

            if ($task->isPrivate() && $oldAssigneeId !== null && $oldAssigneeId !== $task->ownerUserId() && $oldAssigneeId !== $newAssigneeId) {
                $oldAssignee = User::query()->find($oldAssigneeId);
                if ($oldAssignee instanceof User) {
                    $this->access->revokePrivateResourceAccess($actor, $task, $oldAssignee, ModuleCapability::View);
                    $this->access->revokePrivateResourceAccess($actor, $task, $oldAssignee, ModuleCapability::Edit);
                }
            }

            foreach (['title', 'description', 'priority', 'timezone'] as $field) {
                if (array_key_exists($field, $data)) {
                    $task->{$field} = $field === 'description' ? $this->nullableTrim($data[$field]) : trim((string) $data[$field]);
                }
            }
            if (array_key_exists('due_at', $data)) {
                $task->due_at = $dueAt;
            }
            if (array_key_exists('recurrence_frequency', $data)) {
                $task->recurrence_frequency = $frequency;
                $task->recurrence_interval = $frequency === null ? 1 : (int) ($data['recurrence_interval'] ?? $task->recurrence_interval);
            } elseif (array_key_exists('recurrence_interval', $data)) {
                $task->recurrence_interval = (int) $data['recurrence_interval'];
            }
            if ($scheduleChanged) {
                $task->recurrence_anchor_date = $frequency === null ? null : $dueAt->setTimezone($timezone)->startOfDay();
            }
            if (array_key_exists('assignee_id', $data)) {
                $task->assignee_user_id = $newAssigneeId;
            }
            $task->version++;
            $task->save();
            if ($scheduleChanged) {
                $this->synchronizeChecklistDeadlines($task->fresh(), $previousDueAt, $previousTimezone);
            }

            if ($task->isPrivate() && $assignee !== null && $assignee->id !== $task->ownerUserId() && $assignee->id !== $oldAssigneeId) {
                $this->sharePrivateAssignment($actor, $task, $assignee);
            }

            return $task->fresh(['assignee', 'completions.completedBy', 'checklistItems.currentOccurrence.completedBy']);
        }, 3);

        return $this->present($actor, $task);
    }

    /** @param array{expected_version: int} $data
     * @return array<string, mixed>
     */
    public function complete(User $actor, int $taskId, array $data): array
    {
        $task = DB::transaction(function () use ($actor, $taskId, $data): HouseholdTask {
            $this->access->authorize($actor, self::MODULE, ModuleCapability::Edit);
            $task = $this->visibleTasks($actor, [ModuleCapability::Edit, ModuleCapability::Manage], HouseholdTask::query())
                ->whereKey($taskId)->lockForUpdate()->firstOrFail();
            $this->access->authorize($actor, self::MODULE, ModuleCapability::Edit, $task);
            $this->access->authorize($actor, self::MODULE, ModuleCapability::View, $task);
            $canManageShared = $this->canManageShared($actor);
            if (! $this->mayComplete($actor, $task, $canManageShared)) {
                throw new AuthorizationException('Only the task owner, assignee or an authorized household administrator can complete this task.');
            }
            $this->assertVersion($task, (int) $data['expected_version']);
            if ($task->is_completed) {
                throw new ConflictHttpException('This task is already complete. Refresh and try again.');
            }

            $this->finishOccurrence($actor, $task);

            return $task->fresh(['assignee', 'completions.completedBy', 'checklistItems.currentOccurrence.completedBy']);
        }, 3);

        return $this->present($actor, $task);
    }

    /** @return list<array{id: int, occurrence_number: int, completed_by: string|null, completed_at: string, checklist_items: list<array{title: string, is_completed: bool, completed_by: string|null, completed_at: string|null}>}> */
    public function history(User $actor, int $taskId): array
    {
        $this->access->authorize($actor, self::MODULE, ModuleCapability::View);
        $task = $this->findVisible($actor, $taskId, ModuleCapability::View);

        return $task->completions()->with('completedBy')->orderByDesc('completed_at')->orderByDesc('id')->limit(100)->get()
            ->map(function (HouseholdTaskCompletion $completion) use ($task): array {
                $items = HouseholdTaskChecklistOccurrence::query()
                    ->where('task_id', $task->id)
                    ->where('task_occurrence_number', $completion->occurrence_number)
                    ->with('completedBy')->orderBy('id')->get()
                    ->map(static fn (HouseholdTaskChecklistOccurrence $item): array => [
                        'title' => $item->title_snapshot,
                        'is_completed' => $item->completed_at !== null,
                        'completed_by' => $item->completedBy?->name,
                        'completed_at' => $item->completed_at?->toISOString(),
                    ])->all();

                return [
                    'id' => (int) $completion->id,
                    'occurrence_number' => (int) $completion->occurrence_number,
                    'completed_by' => $completion->completedBy?->name,
                    'completed_at' => $completion->completed_at->toISOString(),
                    'checklist_items' => $items,
                ];
            })->all();
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function addChecklistItem(User $actor, int $taskId, array $data): array
    {
        $task = DB::transaction(function () use ($actor, $taskId, $data): HouseholdTask {
            $task = $this->findForEdit($actor, $taskId);
            $this->authorizeTaskUpdate($actor, $task);
            $this->access->authorize($actor, self::MODULE, ModuleCapability::View, $task);
            $this->assertVersion($task, (int) $data['expected_version']);
            $this->assertTaskOpen($task);
            if ($task->checklistItems()->count() >= 100) {
                throw ValidationException::withMessages(['title' => 'A task can contain at most 100 checklist items.']);
            }
            $dueAt = $this->dueAt($data['due_at'] ?? null);
            $lead = (int) ($data['reminder_lead_minutes'] ?? 0);
            $this->assertReminderLead($dueAt, $lead);
            $position = (int) $task->checklistItems()->max('position') + 1;
            [$offsetDays, $localTime] = $this->itemSchedule($task, $dueAt);
            $item = $task->checklistItems()->create([
                'title' => trim($data['title']),
                'position' => $position,
                'due_offset_days' => $offsetDays,
                'due_time' => $localTime,
                'reminder_lead_minutes' => $lead,
            ]);
            HouseholdTaskChecklistOccurrence::query()->create([
                'task_id' => $task->id,
                'checklist_item_id' => $item->id,
                'task_occurrence_number' => $task->occurrence_number,
                'title_snapshot' => $item->title,
                'due_at' => $dueAt,
            ]);
            $task->version++;
            $task->save();

            return $task->fresh(['assignee', 'completions.completedBy', 'checklistItems.currentOccurrence.completedBy']);
        }, 3);

        return $this->present($actor, $task);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateChecklistItem(User $actor, int $taskId, int $itemId, array $data): array
    {
        $task = DB::transaction(function () use ($actor, $taskId, $itemId, $data): HouseholdTask {
            $task = $this->findForEdit($actor, $taskId);
            $this->authorizeTaskUpdate($actor, $task);
            $this->access->authorize($actor, self::MODULE, ModuleCapability::View, $task);
            $this->assertVersion($task, (int) $data['expected_version']);
            $this->assertTaskOpen($task);
            $item = $task->checklistItems()->whereKey($itemId)->lockForUpdate()->firstOrFail();
            $occurrence = $this->currentChecklistOccurrence($task, $item, true);
            $dueAt = array_key_exists('due_at', $data) ? $this->dueAt($data['due_at']) : $occurrence->due_at;
            $lead = (int) ($data['reminder_lead_minutes'] ?? $item->reminder_lead_minutes);
            $this->assertReminderLead($dueAt, $lead);
            if (array_key_exists('title', $data)) {
                $item->title = trim($data['title']);
                $occurrence->title_snapshot = $item->title;
            }
            if (array_key_exists('due_at', $data)) {
                [$item->due_offset_days, $item->due_time] = $this->itemSchedule($task, $dueAt);
                $occurrence->due_at = $dueAt;
            }
            $item->reminder_lead_minutes = $lead;
            $item->save();
            $occurrence->save();
            $task->version++;
            $task->save();

            return $task->fresh(['assignee', 'completions.completedBy', 'checklistItems.currentOccurrence.completedBy']);
        }, 3);

        return $this->present($actor, $task);
    }

    /** @param array{item_ids: list<int>, expected_version: int} $data
     * @return array<string, mixed>
     */
    public function reorderChecklistItems(User $actor, int $taskId, array $data): array
    {
        $task = DB::transaction(function () use ($actor, $taskId, $data): HouseholdTask {
            $task = $this->findForEdit($actor, $taskId);
            $this->authorizeTaskUpdate($actor, $task);
            $this->access->authorize($actor, self::MODULE, ModuleCapability::View, $task);
            $this->assertVersion($task, (int) $data['expected_version']);
            $this->assertTaskOpen($task);
            $items = $task->checklistItems()->lockForUpdate()->get();
            $expectedIds = $items->modelKeys();
            $providedIds = array_map('intval', $data['item_ids']);
            sort($expectedIds);
            $sortedProvidedIds = $providedIds;
            sort($sortedProvidedIds);
            if ($expectedIds !== $sortedProvidedIds) {
                throw ValidationException::withMessages(['item_ids' => 'Provide every checklist item exactly once.']);
            }
            foreach ($providedIds as $position => $id) {
                HouseholdTaskChecklistItem::query()->whereKey($id)->where('task_id', $task->id)->update(['position' => $position]);
            }
            $task->version++;
            $task->save();

            return $task->fresh(['assignee', 'completions.completedBy', 'checklistItems.currentOccurrence.completedBy']);
        }, 3);

        return $this->present($actor, $task);
    }

    /** @param array{completed: bool|int|string, expected_version: int} $data
     * @return array<string, mixed>
     */
    public function completeChecklistItem(User $actor, int $taskId, int $itemId, array $data): array
    {
        $task = DB::transaction(function () use ($actor, $taskId, $itemId, $data): HouseholdTask {
            $this->access->authorize($actor, self::MODULE, ModuleCapability::Edit);
            $task = $this->visibleTasks($actor, [ModuleCapability::Edit, ModuleCapability::Manage], HouseholdTask::query())
                ->whereKey($taskId)->lockForUpdate()->firstOrFail();
            $this->access->authorize($actor, self::MODULE, ModuleCapability::Edit, $task);
            $this->access->authorize($actor, self::MODULE, ModuleCapability::View, $task);
            if (! $this->mayComplete($actor, $task, $this->canManageShared($actor))) {
                throw new AuthorizationException('Only the task owner, assignee or an authorized household administrator can update checklist progress.');
            }
            $this->assertVersion($task, (int) $data['expected_version']);
            $this->assertTaskOpen($task);
            $item = $task->checklistItems()->whereKey($itemId)->firstOrFail();
            $occurrence = $this->currentChecklistOccurrence($task, $item, true);
            $completed = filter_var($data['completed'], FILTER_VALIDATE_BOOLEAN);
            if ($completed === ($occurrence->completed_at !== null)) {
                throw new ConflictHttpException('Checklist progress already has that value. Refresh and try again.');
            }
            $occurrence->completed_at = $completed ? now()->toImmutable() : null;
            $occurrence->completed_by_user_id = $completed ? $actor->id : null;
            $occurrence->save();
            $task->version++;
            $task->save();

            $occurrences = HouseholdTaskChecklistOccurrence::query()
                ->where('task_id', $task->id)
                ->where('task_occurrence_number', $task->occurrence_number);
            if ($completed && $occurrences->exists() && ! (clone $occurrences)->whereNull('completed_at')->exists()) {
                $this->finishOccurrence($actor, $task);
            }

            return $task->fresh(['assignee', 'completions.completedBy', 'checklistItems.currentOccurrence.completedBy']);
        }, 3);

        return $this->present($actor, $task);
    }

    public function deleteChecklistItem(User $actor, int $taskId, int $itemId, int $expectedVersion): void
    {
        DB::transaction(function () use ($actor, $taskId, $itemId, $expectedVersion): void {
            $task = $this->findForEdit($actor, $taskId);
            $this->authorizeTaskUpdate($actor, $task);
            $this->access->authorize($actor, self::MODULE, ModuleCapability::View, $task);
            $this->assertVersion($task, $expectedVersion);
            $this->assertTaskOpen($task);
            $item = $task->checklistItems()->whereKey($itemId)->firstOrFail();
            $occurrenceIds = $item->occurrences()->pluck('id');
            HouseholdReminder::query()->where('source_module', self::MODULE)
                ->where('source_type', 'task_checklist_occurrence')->whereIn('source_id', $occurrenceIds)->delete();
            $item->delete();
            $task->version++;
            $task->save();
        }, 3);
    }

    public function delete(User $actor, int $taskId): void
    {
        DB::transaction(function () use ($actor, $taskId): void {
            $task = $this->findForEdit($actor, $taskId);
            $this->authorizeTaskUpdate($actor, $task);
            $occurrenceIds = HouseholdTaskChecklistOccurrence::query()->where('task_id', $task->id)->pluck('id');
            HouseholdReminder::query()->where('source_module', self::MODULE)
                ->where('source_type', 'task_checklist_occurrence')->whereIn('source_id', $occurrenceIds)->delete();
            $task->delete();
        }, 3);
    }

    private function findVisible(User $actor, int $taskId, ModuleCapability $capability): HouseholdTask
    {
        $this->access->authorize($actor, self::MODULE, $capability);
        $task = $this->visibleTasks($actor, [$capability], HouseholdTask::query())
            ->with(['assignee', 'completions.completedBy', 'checklistItems.currentOccurrence.completedBy'])->whereKey($taskId)->firstOrFail();
        $this->access->authorize($actor, self::MODULE, $capability, $task);

        return $task;
    }

    private function findForEdit(User $actor, int $taskId): HouseholdTask
    {
        return $this->visibleTasks($actor, [ModuleCapability::Edit, ModuleCapability::Manage], HouseholdTask::query())
            ->whereKey($taskId)->lockForUpdate()->firstOrFail();
    }

    /** @param list<ModuleCapability> $capabilities
     * @param  Builder<HouseholdTask>  $query
     * @return Builder<HouseholdTask>
     */
    private function visibleTasks(User $actor, array $capabilities, Builder $query): Builder
    {
        $morphType = (new HouseholdTask)->getMorphClass();
        $values = array_map(fn (ModuleCapability $capability): string => $capability->value, $capabilities);

        return $query->where(function (Builder $visible) use ($actor, $morphType, $values): void {
            $visible->where('household_tasks.is_private', false)
                ->orWhere('household_tasks.owner_user_id', $actor->id)
                ->orWhereExists(function (QueryBuilder $permissions) use ($actor, $morphType, $values): void {
                    $permissions->selectRaw('1')->from('resource_user_permissions')
                        ->whereColumn('resource_user_permissions.resource_id', 'household_tasks.id')
                        ->where('resource_user_permissions.resource_type', $morphType)
                        ->where('resource_user_permissions.user_id', $actor->id)
                        ->whereIn('resource_user_permissions.capability', $values);
                });
        });
    }

    private function authorizeTaskUpdate(User $actor, HouseholdTask $task): void
    {
        if ($task->isPrivate()) {
            if ($task->ownerUserId() !== $actor->id) {
                throw new AuthorizationException('Only the owner can edit a private task.');
            }
            $this->access->authorize($actor, self::MODULE, ModuleCapability::Edit, $task);

            return;
        }

        $this->authorizeSharedManagement($actor);
    }

    private function mayComplete(User $actor, HouseholdTask $task, bool $canManageShared): bool
    {
        if ($task->isPrivate()) {
            return $task->ownerUserId() === $actor->id || (int) $task->assignee_user_id === $actor->id;
        }

        return (int) $task->assignee_user_id === $actor->id || $canManageShared;
    }

    private function canManageShared(User $actor): bool
    {
        if (! User::query()->whereKey($actor->id)->where('is_admin', true)->where('is_active', true)->exists()) {
            return false;
        }
        try {
            $this->access->authorize($actor, self::MODULE, ModuleCapability::Manage);

            return true;
        } catch (AuthorizationException|ModuleDisabled|ModuleNotFound) {
            return false;
        }
    }

    private function authorizeSharedManagement(User $actor): void
    {
        HouseholdSettings::query()->whereKey(1)->lockForUpdate()->firstOrFail();
        $admin = User::query()->whereKey($actor->id)->where('is_admin', true)->where('is_active', true)->lockForUpdate()->first();
        if (! $admin instanceof User) {
            throw new AuthorizationException('Only an active household administrator with Tasks manage access can manage shared tasks.');
        }
        $this->access->authorize($admin, self::MODULE, ModuleCapability::Manage);
    }

    private function resolveAssignee(mixed $assigneeId): ?User
    {
        if ($assigneeId === null || $assigneeId === '') {
            return null;
        }
        $assignee = User::query()->whereKey((int) $assigneeId)->where('is_active', true)->lockForUpdate()->first();
        if (! $assignee instanceof User || ! ModuleUserPermission::query()
            ->where('user_id', $assignee->id)->where('module_key', self::MODULE)
            ->where('can_view', true)->where('can_edit', true)->exists()) {
            throw ValidationException::withMessages(['assignee_id' => 'Choose an active member with Tasks view and edit access.']);
        }

        return $assignee;
    }

    private function sharePrivateAssignment(User $owner, HouseholdTask $task, User $assignee): void
    {
        $this->access->grantPrivateResourceAccess($owner, $task, $assignee, ModuleCapability::View);
        $this->access->grantPrivateResourceAccess($owner, $task, $assignee, ModuleCapability::Edit);
    }

    private function assertVersion(HouseholdTask $task, int $expectedVersion): void
    {
        if ($task->version !== $expectedVersion) {
            throw new ConflictHttpException('This task changed since it was loaded. Refresh it and retry.');
        }
    }

    private function assertTaskOpen(HouseholdTask $task): void
    {
        if ($task->is_completed) {
            throw new ConflictHttpException('This task is already complete. Refresh and try again.');
        }
    }

    private function assertReminderLead(?CarbonImmutable $dueAt, int $leadMinutes): void
    {
        if ($dueAt === null && $leadMinutes > 0) {
            throw ValidationException::withMessages(['reminder_lead_minutes' => 'A reminder lead time needs a due date.']);
        }
    }

    private function currentChecklistOccurrence(
        HouseholdTask $task,
        HouseholdTaskChecklistItem $item,
        bool $lock = false,
    ): HouseholdTaskChecklistOccurrence {
        $query = HouseholdTaskChecklistOccurrence::query()
            ->where('task_id', $task->id)
            ->where('checklist_item_id', $item->id)
            ->where('task_occurrence_number', $task->occurrence_number);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    /** @return array{0: int|null, 1: string|null} */
    /** @return array{?int, ?string} */
    private function itemSchedule(HouseholdTask $task, ?CarbonImmutable $dueAt): array
    {
        if ($dueAt === null) {
            return [null, null];
        }

        $localDue = $dueAt->setTimezone($task->timezone);
        $offsetDays = null;
        if ($task->due_at !== null) {
            $parentDate = CarbonImmutable::parse($task->due_at)->setTimezone($task->timezone)->startOfDay();
            $offsetDays = (int) $parentDate->diffInDays($localDue->startOfDay(), false);
        }

        return [$offsetDays, $localDue->format('H:i:s')];
    }

    private function occurrenceDueAt(HouseholdTask $task, HouseholdTaskChecklistItem $item): ?CarbonImmutable
    {
        if ($task->due_at === null || $item->due_offset_days === null || $item->due_time === null) {
            return null;
        }

        return CarbonImmutable::parse($task->due_at)->setTimezone($task->timezone)->startOfDay()
            ->addDays($item->due_offset_days)
            ->setTimeFromTimeString((string) $item->due_time)->utc();
    }

    private function synchronizeChecklistDeadlines(
        HouseholdTask $task,
        ?CarbonImmutable $previousDueAt,
        string $previousTimezone,
    ): void {
        foreach ($task->checklistItems()->with('currentOccurrence')->lockForUpdate()->get() as $item) {
            $occurrence = $item->currentOccurrence;
            if (! $occurrence instanceof HouseholdTaskChecklistOccurrence || $occurrence->completed_at !== null) {
                continue;
            }

            if ($task->due_at === null) {
                continue;
            }

            if ($item->due_offset_days === null && $occurrence->due_at !== null && $previousDueAt === null) {
                $localDeadline = $occurrence->due_at->setTimezone($previousTimezone);
                $localParent = CarbonImmutable::parse($task->due_at)->setTimezone($task->timezone)->startOfDay();
                $item->due_offset_days = (int) $localParent->diffInDays($localDeadline->setTimezone($task->timezone)->startOfDay(), false);
                $item->due_time = $localDeadline->setTimezone($task->timezone)->format('H:i:s');
                $item->save();
            }

            $newDueAt = $this->occurrenceDueAt($task, $item);
            if ($newDueAt !== null) {
                $occurrence->due_at = $newDueAt;
                $occurrence->save();
            }
        }
    }

    private function finishOccurrence(User $actor, HouseholdTask $task): void
    {
        $completedAt = now()->toImmutable();
        HouseholdTaskCompletion::query()->create([
            'task_id' => $task->id,
            'occurrence_number' => $task->occurrence_number,
            'completed_by_user_id' => $actor->id,
            'completed_at' => $completedAt,
        ]);

        if ($task->recurrence_frequency === null) {
            $task->is_completed = true;
            $task->completed_at = $completedAt;
            $task->completed_by_user_id = $actor->id;
        } else {
            $task->due_at = $this->nextDueDate($task);
            $task->occurrence_number++;
            $task->is_completed = false;
            $task->completed_at = null;
            $task->completed_by_user_id = null;
        }
        $task->version++;
        $task->save();

        if ($task->recurrence_frequency !== null) {
            foreach ($task->checklistItems()->orderBy('position')->orderBy('id')->get() as $item) {
                HouseholdTaskChecklistOccurrence::query()->create([
                    'task_id' => $task->id,
                    'checklist_item_id' => $item->id,
                    'task_occurrence_number' => $task->occurrence_number,
                    'title_snapshot' => $item->title,
                    'due_at' => $this->occurrenceDueAt($task, $item),
                ]);
            }
        }
    }

    private function assertRecurringTaskHasDueDate(?string $frequency, mixed $dueAt): void
    {
        if ($frequency !== null && $dueAt === null) {
            throw ValidationException::withMessages(['due_at' => 'Recurring tasks need a due date.']);
        }
    }

    private function dueAt(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse($value)->utc();
    }

    private function nextDueDate(HouseholdTask $task): CarbonImmutable
    {
        $due = CarbonImmutable::parse($task->due_at)->setTimezone($task->timezone);
        $interval = (int) $task->recurrence_interval;
        $anchor = CarbonImmutable::parse($task->recurrence_anchor_date);
        $next = match ($task->recurrence_frequency) {
            'daily' => $due->addDays($interval),
            'weekly' => $due->addWeeks($interval),
            'monthly' => $this->atAnchoredMonth($due, $due->addMonthsNoOverflow($interval), $anchor->day),
            'yearly' => $this->atAnchoredMonth(
                $due,
                $due->setDate($due->year + $interval, $anchor->month, 1),
                $anchor->day,
            ),
            default => throw new \LogicException('A recurring task needs a supported recurrence frequency.'),
        };

        return $next->utc();
    }

    private function atAnchoredMonth(CarbonImmutable $due, CarbonImmutable $targetMonth, int $anchorDay): CarbonImmutable
    {
        return $targetMonth->day(min($anchorDay, $targetMonth->daysInMonth))
            ->setTime($due->hour, $due->minute, $due->second, $due->microsecond);
    }

    private function nullableTrim(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    /** @return array<string, mixed> */
    private function present(User $actor, HouseholdTask $task, ?bool $canManageShared = null): array
    {
        $canManageShared ??= $this->canManageShared($actor);

        $task->loadMissing(['assignee', 'checklistItems.currentOccurrence.completedBy']);
        $canEdit = $this->canEdit($actor, $task, $canManageShared);

        return [
            'id' => (int) $task->id,
            'title' => $task->title,
            'description' => $task->description,
            'due_at' => $task->due_at?->toISOString(),
            'timezone' => $task->timezone,
            'priority' => $task->priority,
            'recurrence_frequency' => $task->recurrence_frequency,
            'recurrence_interval' => (int) $task->recurrence_interval,
            'visibility' => $task->isPrivate() ? 'private' : 'household',
            'is_completed' => (bool) $task->is_completed,
            'completed_at' => $task->completed_at?->toISOString(),
            'occurrence_number' => (int) $task->occurrence_number,
            'version' => (int) $task->version,
            'assignee' => $task->assignee === null ? null : [
                'id' => (int) $task->assignee->id,
                'name' => $task->assignee->name,
            ],
            'checklist_items' => $task->checklistItems->map(static function (HouseholdTaskChecklistItem $item): array {
                $occurrence = $item->currentOccurrence;

                return [
                    'id' => (int) $item->id,
                    'title' => $item->title,
                    'position' => (int) $item->position,
                    'due_at' => $occurrence?->due_at?->toISOString(),
                    'reminder_lead_minutes' => (int) $item->reminder_lead_minutes,
                    'is_completed' => $occurrence?->completed_at !== null,
                    'completed_by' => $occurrence?->completedBy?->name,
                    'completed_at' => $occurrence?->completed_at?->toISOString(),
                ];
            })->values()->all(),
            'can_edit' => $canEdit,
            'can_edit_checklist' => $canEdit,
            'can_complete' => $this->canComplete($actor, $task, $canManageShared),
            'created_at' => $task->created_at?->toISOString(),
            'updated_at' => $task->updated_at?->toISOString(),
        ];
    }

    private function canEdit(User $actor, HouseholdTask $task, bool $canManageShared): bool
    {
        if (! $task->isPrivate()) {
            return $canManageShared;
        }

        try {
            $this->access->authorize($actor, self::MODULE, ModuleCapability::View, $task);
            if ($task->ownerUserId() !== $actor->id) {
                return false;
            }
            $this->access->authorize($actor, self::MODULE, ModuleCapability::Edit, $task);

            return true;
        } catch (AuthorizationException|ModuleDisabled|ModuleNotFound) {
            return false;
        }
    }

    private function canComplete(User $actor, HouseholdTask $task, bool $canManageShared): bool
    {
        if ($task->is_completed || ! $this->mayComplete($actor, $task, $canManageShared)) {
            return false;
        }

        try {
            $this->access->authorize($actor, self::MODULE, ModuleCapability::View, $task);
            $this->access->authorize($actor, self::MODULE, ModuleCapability::Edit, $task);

            return true;
        } catch (AuthorizationException|ModuleDisabled|ModuleNotFound) {
            return false;
        }
    }
}
