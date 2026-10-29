<?php

namespace App\Services\Reminders;

use App\Enums\ModuleCapability;
use App\Exceptions\ModuleDisabled;
use App\Exceptions\ModuleNotFound;
use App\Mail\UpcomingReminderMail;
use App\Models\FinanceRecurringEntry;
use App\Models\HouseholdReminder;
use App\Models\HouseholdTask;
use App\Models\HouseholdTaskChecklistItem;
use App\Models\HouseholdTaskChecklistOccurrence;
use App\Models\PantryItem;
use App\Models\User;
use App\Models\UtilityMeter;
use App\Models\UtilityReading;
use App\Models\Vehicle;
use App\Models\VehicleInsurancePolicy;
use App\Models\VehicleMaintenanceRecord;
use App\Services\Modules\ModuleAccessService;
use App\Services\Modules\ModuleStateService;
use App\Services\Utilities\DecimalValue;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class ReminderService
{
    private const SOURCES = ['finance', 'vehicles', 'utilities', 'pantry', 'tasks'];

    public function __construct(
        private readonly ModuleAccessService $access,
        private readonly ModuleStateService $states,
    ) {}

    /** @return array{email_enabled: bool, lead_days: int, sources: array<string, bool>} */
    public function preferences(User $member): array
    {
        return [
            'email_enabled' => (bool) $member->reminder_email_enabled,
            'lead_days' => (int) ($member->reminder_lead_days ?? 3),
            'sources' => $this->sourcePreferences($member),
        ];
    }

    /** @param array<string, mixed> $values
     * @return array{email_enabled: bool, lead_days: int, sources: array<string, bool>}
     */
    public function updatePreferences(User $member, array $values): array
    {
        $member = User::query()->whereKey($member->id)->where('is_active', true)->firstOrFail();

        if (array_key_exists('email_enabled', $values)) {
            $member->reminder_email_enabled = (bool) $values['email_enabled'];
        }

        if (array_key_exists('lead_days', $values)) {
            $member->reminder_lead_days = (int) $values['lead_days'];
        }

        if (array_key_exists('sources', $values)) {
            $member->setAttribute('reminder_sources', array_replace(
                $this->sourcePreferences($member),
                array_map(static fn (mixed $enabled): bool => (bool) $enabled, $values['sources']),
            ));
        }

        $member->save();

        return $this->preferences($member->fresh());
    }

    /** @return array{reminders: list<array<string, mixed>>, unread_count: int} */
    public function index(User $member): array
    {
        $rows = HouseholdReminder::query()
            ->where('recipient_user_id', $member->id)
            ->whereNull('dismissed_at')
            ->orderBy('due_on')
            ->orderBy('id')
            ->limit(100)
            ->get();
        $reminders = [];

        foreach ($rows as $row) {
            if (! ($this->sourcePreferences($member)[$row->source_module] ?? false)) {
                continue;
            }

            $resolved = $this->resolve($member, $row);

            if ($resolved === null) {
                // Purge records whose source was removed, disabled or is no longer visible.
                $row->delete();

                continue;
            }

            $reminders[] = [
                'id' => $row->id,
                'source_module' => $row->source_module,
                'title' => $resolved['title'],
                'due_on' => CarbonImmutable::parse($row->due_on)->format('Y-m-d'),
                'href' => $resolved['href'],
                'is_read' => $row->read_at !== null,
                'created_at' => $row->created_at?->toISOString(),
            ];
        }

        return [
            'reminders' => $reminders,
            'unread_count' => count(array_filter($reminders, static fn (array $item): bool => ! $item['is_read'])),
        ];
    }

    public function update(User $member, int $reminderId, string $action): void
    {
        $reminder = HouseholdReminder::query()
            ->whereKey($reminderId)
            ->where('recipient_user_id', $member->id)
            ->whereNull('dismissed_at')
            ->first();

        if (! $reminder instanceof HouseholdReminder
            || ! ($this->sourcePreferences($member)[$reminder->source_module] ?? false)
            || $this->resolve($member, $reminder) === null) {
            $reminder?->delete();
            throw new NotFoundHttpException('The reminder is unavailable.');
        }

        if ($action === 'read') {
            $reminder->read_at ??= now();
            $reminder->save();

            return;
        }

        $reminder->dismissed_at = now();
        $reminder->save();
    }

    /** Generate one reminder per visible member/source occurrence and send opted-in email once. */
    public function generate(): array
    {
        $today = CarbonImmutable::today();
        $limit = $today->addDays(30);
        $created = 0;
        $sent = 0;

        foreach ($this->candidates($today, $limit) as $candidate) {
            foreach (User::query()->where('is_active', true)->orderBy('id')->cursor() as $recipient) {
                $preferences = $this->preferences($recipient);

                if (! ($preferences['sources'][$candidate['source_module']] ?? false)) {
                    continue;
                }

                if ($candidate['source_module'] !== 'utilities'
                    && CarbonImmutable::parse($candidate['due_on'])->gt($today->addDays($preferences['lead_days']))) {
                    continue;
                }

                $resolved = $this->resolveCandidate($recipient, $candidate);

                if ($resolved === null) {
                    continue;
                }

                $reminder = HouseholdReminder::query()->firstOrCreate(
                    [
                        'recipient_user_id' => $recipient->id,
                        'source_module' => $candidate['source_module'],
                        'source_type' => $candidate['source_type'],
                        'source_id' => $candidate['source_id'],
                        'occurrence_key' => $candidate['occurrence_key'],
                    ],
                    [
                        'due_on' => $candidate['due_on'],
                        'email_requested' => $preferences['email_enabled'],
                    ],
                );

                if ($reminder->wasRecentlyCreated) {
                    $created++;
                }

                $emailRequested = $this->syncPendingEmailRequest($reminder, $preferences['email_enabled']);

                if ($emailRequested
                    && $this->sendEmail($recipient, $reminder)) {
                    $sent++;
                }
            }
        }

        return ['created' => $created, 'sent' => $sent];
    }

    /** Generate task checklist reminders at their configured local minute. */
    public function generateTaskReminders(): array
    {
        if (! $this->states->isEnabled('tasks')) {
            return ['created' => 0, 'sent' => 0];
        }

        $now = now()->toImmutable();
        $created = 0;
        $sent = 0;

        HouseholdTaskChecklistOccurrence::query()
            ->whereNotNull('due_at')
            ->whereNull('completed_at')
            ->whereHas('item')
            ->whereHas('task', fn ($query) => $query->where('is_completed', false))
            ->with(['task.assignee', 'task.createdBy', 'item'])
            ->chunkById(250, function ($occurrences) use ($now, &$created, &$sent): void {
                foreach ($occurrences as $occurrence) {
                    $task = $occurrence->task;
                    $item = $occurrence->item;
                    if (! $task instanceof HouseholdTask || ! $item instanceof HouseholdTaskChecklistItem
                        || (int) $task->occurrence_number !== (int) $occurrence->task_occurrence_number
                        || $task->is_completed || $occurrence->completed_at !== null) {
                        continue;
                    }

                    $recipientId = $task->assignee_user_id ?? $task->owner_user_id ?? $task->created_by_user_id;
                    $recipient = $recipientId === null
                        ? null
                        : User::query()->whereKey($recipientId)->where('is_active', true)->first();
                    if (! $recipient instanceof User
                        || ! ($this->sourcePreferences($recipient)['tasks'] ?? false)
                        || ! $this->canView($recipient, 'tasks', $task)) {
                        continue;
                    }

                    $dueAt = $occurrence->due_at;
                    $localDue = $dueAt->setTimezone($task->timezone);
                    $localNow = $now->setTimezone($task->timezone);
                    $scheduleKey = (int) $dueAt->timestamp;
                    $events = [];

                    if ($item->reminder_lead_minutes > 0 && $dueAt->isFuture()
                        && $dueAt->subMinutes($item->reminder_lead_minutes)->lessThanOrEqualTo($now)) {
                        $events[] = 'task:'.$occurrence->task_occurrence_number.':lead:'.$scheduleKey;
                    }
                    if ($dueAt->lessThanOrEqualTo($now)) {
                        $events[] = 'task:'.$occurrence->task_occurrence_number.':due:'.$scheduleKey;
                        if ($localNow->toDateString() > $localDue->toDateString()
                            && $localNow->format('H:i') >= $localDue->format('H:i')) {
                            $events[] = 'task:'.$occurrence->task_occurrence_number.':daily:'.$scheduleKey.':'.$localNow->format('Ymd');
                        }
                    }

                    foreach ($events as $eventKey) {
                        $resolved = $this->resolveTaskChecklistOccurrence($recipient, $occurrence, $eventKey);
                        if ($resolved === null) {
                            continue;
                        }
                        $reminder = HouseholdReminder::query()->firstOrCreate(
                            [
                                'recipient_user_id' => $recipient->id,
                                'source_module' => 'tasks',
                                'source_type' => 'task_checklist_occurrence',
                                'source_id' => $occurrence->id,
                                'occurrence_key' => $eventKey,
                            ],
                            [
                                'due_on' => $resolved['due_on'],
                                'email_requested' => $this->preferences($recipient)['email_enabled'],
                            ],
                        );
                        if ($reminder->wasRecentlyCreated) {
                            $created++;
                        }
                        if ($this->syncPendingEmailRequest($reminder, $this->preferences($recipient)['email_enabled'])
                            && $this->sendEmail($recipient, $reminder)) {
                            $sent++;
                        }
                    }
                }
            });

        return ['created' => $created, 'sent' => $sent];
    }

    /** @return list<array{source_module: string, source_type: string, source_id: int, occurrence_key: string, due_on: string}> */
    private function candidates(CarbonImmutable $today, CarbonImmutable $limit): array
    {
        $items = [];

        if ($this->states->isEnabled('finance')) {
            foreach (FinanceRecurringEntry::query()
                ->where('is_active', true)
                ->whereNotNull('next_due_on')
                ->whereDate('next_due_on', '>=', $today->toDateString())
                ->whereDate('next_due_on', '<=', $limit->toDateString())
                ->where(fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today->toDateString()))
                ->orderBy('next_due_on')->limit(500)->get() as $entry) {
                $due = CarbonImmutable::parse($entry->next_due_on)->format('Y-m-d');
                $items[] = $this->candidate('finance', 'finance_recurring_entry', $entry->id, $due, $due);
            }
        }

        if ($this->states->isEnabled('vehicles')) {
            foreach (VehicleInsurancePolicy::query()
                ->whereDate('valid_until', '>=', $today->toDateString())
                ->whereDate('valid_until', '<=', $limit->toDateString())
                ->orderBy('valid_until')->limit(500)->get() as $policy) {
                $due = CarbonImmutable::parse($policy->valid_until)->format('Y-m-d');
                $items[] = $this->candidate('vehicles', 'vehicle_insurance', $policy->id, $due, $due);
            }

            foreach (VehicleMaintenanceRecord::query()
                ->whereNotNull('next_due_on')
                ->whereDate('next_due_on', '>=', $today->toDateString())
                ->whereDate('next_due_on', '<=', $limit->toDateString())
                ->orderBy('next_due_on')->limit(500)->get() as $record) {
                $due = CarbonImmutable::parse($record->next_due_on)->format('Y-m-d');
                $items[] = $this->candidate('vehicles', 'vehicle_maintenance', $record->id, $due, $due);
            }
        }

        if ($this->states->isEnabled('pantry')) {
            foreach (PantryItem::query()
                ->whereNotNull('best_before_on')
                ->whereDate('best_before_on', '>=', $today->toDateString())
                ->whereDate('best_before_on', '<=', $limit->toDateString())
                ->orderBy('best_before_on')->limit(500)->get() as $item) {
                $due = CarbonImmutable::parse($item->best_before_on)->format('Y-m-d');
                $items[] = $this->candidate('pantry', 'pantry_item', $item->id, $due, $due);
            }
        }

        if ($this->states->isEnabled('utilities')) {
            foreach (UtilityMeter::query()->whereNotNull('alert_threshold')->orderBy('id')->limit(500)->get() as $meter) {
                /** @var Collection<int, UtilityReading> $readings */
                $readings = $meter->readings()->orderByDesc('reading_date')->orderByDesc('id')->limit(2)->get();

                if ($readings->count() !== 2) {
                    continue;
                }

                $latest = $readings[0];
                $previous = $readings[1];
                if (DecimalValue::compare((string) $latest->value, (string) $previous->value) < 0) {
                    continue;
                }
                $usage = DecimalValue::subtract((string) $latest->value, (string) $previous->value);
                if (DecimalValue::compare($usage, (string) $meter->alert_threshold) <= 0) {
                    continue;
                }

                $items[] = $this->candidate('utilities', 'utility_meter', $meter->id, 'reading:'.$latest->id, $today->toDateString());
            }
        }

        return $items;
    }

    /** @return array{source_module: string, source_type: string, source_id: int, occurrence_key: string, due_on: string} */
    private function candidate(string $module, string $type, int $id, string $occurrence, string $dueOn): array
    {
        return [
            'source_module' => $module,
            'source_type' => $type,
            'source_id' => $id,
            'occurrence_key' => $occurrence,
            'due_on' => $dueOn,
        ];
    }

    /** @param array{source_module: string, source_type: string, source_id: int, occurrence_key: string, due_on: string} $candidate
     * @return array{title: string, href: string, due_on: string}|null
     */
    private function resolveCandidate(User $member, array $candidate): ?array
    {
        $source = $this->source($candidate['source_type'], $candidate['source_id']);

        return $source instanceof Model
            ? $this->resolveSource($member, $candidate['source_module'], $candidate['source_type'], $source, $candidate['occurrence_key'])
            : null;
    }

    /** @return array{title: string, href: string, due_on: string}|null */
    private function resolve(User $member, HouseholdReminder $reminder): ?array
    {
        if (! $member->is_active || ! $this->states->isEnabled($reminder->source_module)) {
            return null;
        }

        $source = $this->source($reminder->source_type, (int) $reminder->source_id);

        return $source instanceof Model
            ? $this->resolveSource($member, $reminder->source_module, $reminder->source_type, $source, $reminder->occurrence_key)
            : null;
    }

    /** @return array{title: string, href: string, due_on: string}|null */
    private function resolveSource(User $member, string $module, string $type, Model $source, string $occurrence): ?array
    {
        if ($source instanceof HouseholdTaskChecklistOccurrence) {
            return $this->resolveTaskChecklistOccurrence($member, $source, $occurrence);
        }

        if (! $this->canView($member, $module, $source)) {
            return null;
        }

        if ($source instanceof FinanceRecurringEntry) {
            if (! $source->is_active || $source->next_due_on === null) {
                return null;
            }

            $nextDueOn = CarbonImmutable::parse($source->next_due_on);
            if ($nextDueOn->format('Y-m-d') !== $occurrence
                || ($source->ends_on !== null && CarbonImmutable::parse($source->ends_on)->lt($nextDueOn))) {
                return null;
            }

            return ['title' => 'Finance · '.$source->description.' is due', 'href' => '/modules/finance', 'due_on' => $occurrence];
        }

        if ($source instanceof VehicleInsurancePolicy || $source instanceof VehicleMaintenanceRecord) {
            $vehicle = Vehicle::query()->find($source->vehicle_id);
            if (! $vehicle instanceof Vehicle || ! $this->canView($member, 'vehicles', $vehicle)) {
                return null;
            }

            if ($source instanceof VehicleInsurancePolicy) {
                if (CarbonImmutable::parse($source->valid_until)->format('Y-m-d') !== $occurrence) {
                    return null;
                }

                return ['title' => 'Insurance renewal · '.$vehicle->name, 'href' => '/modules/vehicles/'.$vehicle->id, 'due_on' => $occurrence];
            }

            if ($source->next_due_on === null || CarbonImmutable::parse($source->next_due_on)->format('Y-m-d') !== $occurrence) {
                return null;
            }

            return ['title' => 'Vehicle service · '.$vehicle->name.' · '.$source->title, 'href' => '/modules/vehicles/'.$vehicle->id, 'due_on' => $occurrence];
        }

        if ($source instanceof PantryItem) {
            if ($source->best_before_on === null || CarbonImmutable::parse($source->best_before_on)->format('Y-m-d') !== $occurrence) {
                return null;
            }

            return ['title' => 'Best before · '.$source->name, 'href' => '/modules/pantry', 'due_on' => $occurrence];
        }

        if ($source instanceof UtilityMeter) {
            if ($source->alert_threshold === null) {
                return null;
            }
            /** @var Collection<int, UtilityReading> $readings */
            $readings = $source->readings()->orderByDesc('reading_date')->orderByDesc('id')->limit(2)->get();
            if ($readings->count() !== 2) {
                return null;
            }
            $latest = $readings[0];
            $previous = $readings[1];
            if ('reading:'.$latest->id !== $occurrence
                || ! $this->canView($member, 'utilities', $latest)
                || ! $this->canView($member, 'utilities', $previous)
                || DecimalValue::compare((string) $latest->value, (string) $previous->value) < 0) {
                return null;
            }
            $usage = DecimalValue::subtract((string) $latest->value, (string) $previous->value);
            if (DecimalValue::compare($usage, (string) $source->alert_threshold) <= 0) {
                return null;
            }

            return ['title' => 'Usage threshold exceeded · '.$source->name, 'href' => '/modules/utilities/'.$source->id, 'due_on' => CarbonImmutable::parse($latest->reading_date)->format('Y-m-d')];
        }

        return null;
    }

    private function source(string $type, int $id): ?Model
    {
        return match ($type) {
            'finance_recurring_entry' => FinanceRecurringEntry::query()->find($id),
            'vehicle_insurance' => VehicleInsurancePolicy::query()->find($id),
            'vehicle_maintenance' => VehicleMaintenanceRecord::query()->find($id),
            'utility_meter' => UtilityMeter::query()->find($id),
            'pantry_item' => PantryItem::query()->find($id),
            'task_checklist_occurrence' => HouseholdTaskChecklistOccurrence::query()->with(['task.assignee', 'task.createdBy', 'item'])->find($id),
            default => null,
        };
    }

    /** @return array{title: string, href: string, due_on: string}|null */
    private function resolveTaskChecklistOccurrence(User $member, HouseholdTaskChecklistOccurrence $occurrence, string $key): ?array
    {
        $task = $occurrence->task;
        $item = $occurrence->item;
        if (! $task instanceof HouseholdTask || ! $item instanceof HouseholdTaskChecklistItem
            || $task->is_completed || $occurrence->completed_at !== null
            || (int) $task->occurrence_number !== (int) $occurrence->task_occurrence_number
            || $occurrence->due_at === null || ! $this->canView($member, 'tasks', $task)) {
            return null;
        }

        $recipientId = $task->assignee_user_id ?? $task->owner_user_id ?? $task->created_by_user_id;
        if ($recipientId !== $member->id) {
            return null;
        }

        $validPrefix = 'task:'.$occurrence->task_occurrence_number.':';
        if (! str_starts_with($key, $validPrefix)
            || ! str_contains($key, ':'.(int) $occurrence->due_at->timestamp)) {
            return null;
        }

        return [
            'title' => 'Task · '.$task->title.' · '.$occurrence->title_snapshot.' is due',
            'href' => '/modules/tasks#task-'.$task->id.'-checklist-item-'.$item->id,
            'due_on' => $occurrence->due_at->setTimezone($task->timezone)->toDateString(),
        ];
    }

    private function canView(User $member, string $module, Model $source): bool
    {
        try {
            $this->access->authorize($member, $module, ModuleCapability::View, $source);

            return true;
        } catch (AuthorizationException|ModuleDisabled|ModuleNotFound) {
            return false;
        }
    }

    private function sendEmail(User $recipient, HouseholdReminder $reminder): bool
    {
        /** @var array{email: string, title: string, due_on: string}|null $delivery */
        $delivery = null;

        DB::transaction(function () use ($recipient, $reminder, &$delivery): void {
            $currentReminder = HouseholdReminder::query()->whereKey($reminder->id)->lockForUpdate()->first();
            if (! $currentReminder instanceof HouseholdReminder || $currentReminder->emailed_at !== null
                || $currentReminder->email_claimed_at !== null || $currentReminder->dismissed_at !== null) {
                return;
            }

            $currentRecipient = User::query()->whereKey($recipient->id)->where('is_active', true)->first();
            if (! $currentRecipient instanceof User || ! $currentRecipient->reminder_email_enabled
                || ! ($currentReminder->email_requested ?? false)
                || ! ($this->sourcePreferences($currentRecipient)[$currentReminder->source_module] ?? false)) {
                return;
            }

            $current = $this->resolve($currentRecipient, $currentReminder);
            if ($current === null) {
                $currentReminder->delete();

                return;
            }

            // Commit this claim before SMTP so an ambiguous send outcome cannot be retried as a duplicate.
            $currentReminder->email_claimed_at = now();
            $currentReminder->save();

            $delivery = [
                'email' => $currentRecipient->email,
                'title' => $current['title'],
                'due_on' => $current['due_on'],
            ];
        }, 5);

        if (! is_array($delivery)) {
            return false;
        }

        try {
            Mail::to($delivery['email'])->send(new UpcomingReminderMail($delivery['title'], $delivery['due_on']));
        } catch (Throwable) {
            // SMTP errors can have ambiguous delivery outcomes, so keep the durable claim to avoid duplicates.
            throw new RuntimeException('Reminder email delivery failed.');
        }

        DB::transaction(function () use ($reminder): void {
            $currentReminder = HouseholdReminder::query()->whereKey($reminder->id)->lockForUpdate()->first();
            if (! $currentReminder instanceof HouseholdReminder || $currentReminder->emailed_at !== null
                || $currentReminder->email_claimed_at === null) {
                return;
            }

            $currentReminder->emailed_at = now();
            $currentReminder->save();
        }, 5);

        return true;
    }

    /** Update the email intent while the reminder remains unclaimed and return its current intent. */
    private function syncPendingEmailRequest(HouseholdReminder $reminder, bool $emailEnabled): bool
    {
        return DB::transaction(function () use ($reminder, $emailEnabled): bool {
            $currentReminder = HouseholdReminder::query()->whereKey($reminder->id)->lockForUpdate()->first();
            if (! $currentReminder instanceof HouseholdReminder || $currentReminder->emailed_at !== null
                || $currentReminder->email_claimed_at !== null || $currentReminder->dismissed_at !== null) {
                return false;
            }

            if ((bool) $currentReminder->email_requested !== $emailEnabled) {
                $currentReminder->email_requested = $emailEnabled;
                $currentReminder->save();
            }

            return $emailEnabled;
        }, 5);
    }

    /** @return array<string, bool> */
    private function sourcePreferences(User $member): array
    {
        $saved = $member->getAttribute('reminder_sources');
        $saved = is_array($saved) ? $saved : [];
        $defaults = array_fill_keys(self::SOURCES, true);

        foreach (self::SOURCES as $source) {
            if (array_key_exists($source, $saved)) {
                $defaults[$source] = (bool) $saved[$source];
            }
        }

        return $defaults;
    }
}
