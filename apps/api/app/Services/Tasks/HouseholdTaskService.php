<?php

namespace App\Services\Tasks;

use App\Enums\ModuleCapability;
use App\Exceptions\ModuleDisabled;
use App\Exceptions\ModuleNotFound;
use App\Models\HouseholdSettings;
use App\Models\HouseholdTask;
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
            ->with(['assignee', 'completions.completedBy']);

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

            if ($task->isPrivate() && $assignee !== null && $assignee->id !== $task->ownerUserId() && $assignee->id !== $oldAssigneeId) {
                $this->sharePrivateAssignment($actor, $task, $assignee);
            }

            return $task->fresh(['assignee', 'completions.completedBy']);
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

            return $task->fresh(['assignee', 'completions.completedBy']);
        }, 3);

        return $this->present($actor, $task);
    }

    /** @return list<array{id: int, occurrence_number: int, completed_by: string|null, completed_at: string}> */
    public function history(User $actor, int $taskId): array
    {
        $this->access->authorize($actor, self::MODULE, ModuleCapability::View);
        $task = $this->findVisible($actor, $taskId, ModuleCapability::View);

        return $task->completions()->with('completedBy')->orderByDesc('completed_at')->orderByDesc('id')->limit(100)->get()
            ->map(fn (HouseholdTaskCompletion $completion): array => [
                'id' => (int) $completion->id,
                'occurrence_number' => (int) $completion->occurrence_number,
                'completed_by' => $completion->completedBy?->name,
                'completed_at' => $completion->completed_at->toISOString(),
            ])->all();
    }

    public function delete(User $actor, int $taskId): void
    {
        DB::transaction(function () use ($actor, $taskId): void {
            $task = $this->findForEdit($actor, $taskId);
            $this->authorizeTaskUpdate($actor, $task);
            $task->delete();
        }, 3);
    }

    private function findVisible(User $actor, int $taskId, ModuleCapability $capability): HouseholdTask
    {
        $this->access->authorize($actor, self::MODULE, $capability);
        $task = $this->visibleTasks($actor, [$capability], HouseholdTask::query())
            ->with(['assignee', 'completions.completedBy'])->whereKey($taskId)->firstOrFail();
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

        if (! $task->relationLoaded('assignee')) {
            $task->load('assignee');
        }

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
            'can_edit' => $this->canEdit($actor, $task, $canManageShared),
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
