<?php

namespace App\Services\Tracking;

use App\Enums\ModuleCapability;
use App\Models\TrackerDefinition;
use App\Models\TrackerEntry;
use App\Models\TrackerEntryValue;
use App\Models\TrackerField;
use App\Models\User;
use App\Services\Modules\ModuleAccessService;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TrackerEntryService
{
    public function __construct(
        private readonly ModuleAccessService $access,
        private readonly TrackerSchemaService $schemas,
    ) {}

    /** @return array{entries: list<array<string, mixed>>, has_more: bool} */
    public function history(
        User $actor,
        int $trackerId,
        int $limit = 30,
        ?string $beforeRecordedAt = null,
        ?int $beforeId = null,
    ): array {
        $definition = $this->schemas->findVisible($actor, $trackerId, ModuleCapability::View);
        $this->access->authorize($actor, 'tracking', ModuleCapability::View, $definition);

        $query = $this->visibleEntries($actor, $definition);

        if ($beforeRecordedAt !== null && $beforeId !== null) {
            $cursorDate = CarbonImmutable::parse($beforeRecordedAt);
            $query->where(function (Builder $cursor) use ($cursorDate, $beforeId): void {
                $cursor->where('recorded_at', '<', $cursorDate)
                    ->orWhere(function (Builder $sameTime) use ($cursorDate, $beforeId): void {
                        $sameTime->where('recorded_at', $cursorDate)
                            ->where('id', '<', $beforeId);
                    });
            });
        }

        $page = $query
            ->with(['values' => fn (HasMany $query) => $query->orderBy('id')])
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get();
        $hasMore = $page->count() > $limit;

        return [
            'entries' => $page->take($limit)
                ->map(fn (TrackerEntry $entry): array => $this->present($actor, $entry))
                ->all(),
            'has_more' => $hasMore,
        ];
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(User $actor, int $trackerId, array $data): array
    {
        return DB::transaction(function () use ($actor, $trackerId, $data): array {
            $definition = TrackerDefinition::query()
                ->whereKey($trackerId)
                ->lockForUpdate()
                ->firstOrFail();
            $this->access->authorize($actor, 'tracking', ModuleCapability::Create, $definition);

            if ($definition->schema_version !== (int) $data['schema_version']) {
                abort(409, 'The tracker schema changed. Reload it before saving this entry.');
            }

            $fields = $definition->fields()
                ->whereNull('retired_version')
                ->orderBy('sort_order')
                ->lockForUpdate()
                ->get();
            $values = $this->validatedValues($fields, $data['values']);
            $entry = TrackerEntry::query()->create([
                'tracker_definition_id' => $definition->id,
                'owner_user_id' => $actor->id,
                'schema_version' => $definition->schema_version,
                'recorded_at' => CarbonImmutable::parse($data['recorded_at']),
                'note' => $this->nullableTrim($data['note'] ?? null),
            ]);

            $this->writeValues($entry, $values, $fields);

            return $this->present($actor, $entry->fresh(['values']));
        }, 5);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(User $actor, int $entryId, array $data): array
    {
        return DB::transaction(function () use ($actor, $entryId, $data): array {
            $entry = TrackerEntry::query()
                ->whereKey($entryId)
                ->lockForUpdate()
                ->firstOrFail();
            $definition = TrackerDefinition::query()
                ->whereKey($entry->tracker_definition_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->access->authorize($actor, 'tracking', ModuleCapability::Edit, $entry);
            $this->access->authorize($actor, 'tracking', ModuleCapability::Edit, $definition);

            if ($definition->schema_version !== (int) $data['schema_version']) {
                abort(409, 'The tracker schema changed. Reload this entry before saving it.');
            }

            $fields = $definition->fields()
                ->whereNull('retired_version')
                ->orderBy('sort_order')
                ->lockForUpdate()
                ->get();
            $values = $this->validatedValues($fields, $data['values']);
            $entry->forceFill([
                'schema_version' => $definition->schema_version,
                'recorded_at' => CarbonImmutable::parse($data['recorded_at']),
                'note' => $this->nullableTrim($data['note'] ?? null),
            ])->save();

            if ($fields->isNotEmpty()) {
                $entry->values()->whereIn('tracker_field_id', $fields->modelKeys())->delete();
            }

            $this->writeValues($entry, $values, $fields);

            return $this->present($actor, $entry->fresh(['values']));
        }, 5);
    }

    public function delete(User $actor, int $entryId): void
    {
        DB::transaction(function () use ($actor, $entryId): void {
            $entry = TrackerEntry::query()
                ->whereKey($entryId)
                ->lockForUpdate()
                ->firstOrFail();
            $definition = TrackerDefinition::query()
                ->whereKey($entry->tracker_definition_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->access->authorize($actor, 'tracking', ModuleCapability::Edit, $entry);
            $this->access->authorize($actor, 'tracking', ModuleCapability::Edit, $definition);
            $entry->delete();
        }, 5);
    }

    private function visibleEntries(User $actor, TrackerDefinition $definition): Builder
    {
        $morphType = (new TrackerEntry)->getMorphClass();

        return TrackerEntry::query()
            ->where('tracker_definition_id', $definition->id)
            ->where(function (Builder $query) use ($actor, $morphType): void {
                $query->where('owner_user_id', $actor->id)
                    ->orWhereExists(function (QueryBuilder $permissions) use ($actor, $morphType): void {
                        $permissions->selectRaw('1')
                            ->from('resource_user_permissions')
                            ->whereColumn('resource_user_permissions.resource_id', 'tracker_entries.id')
                            ->where('resource_user_permissions.resource_type', $morphType)
                            ->where('resource_user_permissions.user_id', $actor->id)
                            ->where('resource_user_permissions.capability', ModuleCapability::View->value);
                    });
            });
    }

    /**
     * @param  iterable<TrackerField>  $fields
     * @param  array<array-key, mixed>  $rawValues
     * @return array<string, mixed>
     */
    private function validatedValues(iterable $fields, array $rawValues): array
    {
        $fieldsByKey = [];

        foreach ($fields as $field) {
            $fieldsByKey[$field->field_key] = $field;
        }

        $errors = [];
        foreach (array_keys($rawValues) as $key) {
            if (! is_string($key) || ! isset($fieldsByKey[$key])) {
                $errors['values'][] = 'The entry contains a field that is not in the current tracker schema.';
            }
        }

        $validated = [];

        foreach ($fieldsByKey as $key => $field) {
            $value = $rawValues[$key] ?? null;
            $empty = $value === null || $value === '';

            if ($empty) {
                if ($field->is_required) {
                    $errors['values.'.$key][] = 'This field is required.';
                }

                continue;
            }

            switch ($field->data_type) {
                case 'number':
                    $decimal = DecimalValue::normalize($value);

                    if ($decimal === null) {
                        $errors['values.'.$key][] = 'Enter a decimal with up to 14 whole and 6 fractional digits.';

                        break;
                    }

                    if ($field->minimum !== null && DecimalValue::compare($decimal, $field->minimum) < 0) {
                        $errors['values.'.$key][] = 'The value is below the allowed minimum.';
                    } elseif ($field->maximum !== null && DecimalValue::compare($decimal, $field->maximum) > 0) {
                        $errors['values.'.$key][] = 'The value is above the allowed maximum.';
                    } else {
                        $validated[$key] = $decimal;
                    }

                    break;

                case 'text':
                    if (! is_string($value) || strlen(trim($value)) > 5000) {
                        $errors['values.'.$key][] = 'Enter text with no more than 5000 characters.';
                    } else {
                        $validated[$key] = trim($value);
                    }

                    break;

                case 'date':
                    if (! is_string($value)) {
                        $errors['values.'.$key][] = 'Enter a calendar date in YYYY-MM-DD format.';

                        break;
                    }

                    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
                    if ($date === false || $date->format('Y-m-d') !== $value) {
                        $errors['values.'.$key][] = 'Enter a valid calendar date in YYYY-MM-DD format.';
                    } else {
                        $validated[$key] = $value;
                    }

                    break;

                case 'boolean':
                    if (is_bool($value)) {
                        $validated[$key] = $value;
                    } elseif (in_array($value, [0, 1, '0', '1', 'true', 'false'], true)) {
                        $validated[$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                    } else {
                        $errors['values.'.$key][] = 'Choose yes or no.';
                    }

                    break;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  iterable<TrackerField>  $fields
     */
    private function writeValues(TrackerEntry $entry, array $values, iterable $fields): void
    {
        $fieldsByKey = [];

        foreach ($fields as $field) {
            $fieldsByKey[$field->field_key] = $field;
        }

        foreach ($values as $key => $value) {
            $field = $fieldsByKey[$key];
            $entry->values()->create([
                'tracker_field_id' => $field->id,
                'field_key_snapshot' => $field->field_key,
                'field_label_snapshot' => $field->label,
                'data_type_snapshot' => $field->data_type,
                'unit_snapshot' => $field->unit,
                'decimal_value' => $field->data_type === 'number' ? $value : null,
                'text_value' => $field->data_type === 'text' ? $value : null,
                'date_value' => $field->data_type === 'date' ? $value : null,
                'boolean_value' => $field->data_type === 'boolean' ? $value : null,
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function present(User $actor, TrackerEntry $entry): array
    {
        if (! $entry->relationLoaded('values')) {
            $entry->load('values');
        }

        return [
            'id' => $entry->id,
            'tracker_id' => $entry->tracker_definition_id,
            'schema_version' => $entry->schema_version,
            'recorded_at' => $entry->recorded_at?->toISOString(),
            'note' => $entry->note,
            'can_edit' => $this->canEdit($actor, $entry),
            'values' => $entry->values
                ->map(function (TrackerEntryValue $value): array {
                    $stored = match ($value->data_type_snapshot) {
                        'number' => $value->decimal_value,
                        'text' => $value->text_value,
                        'date' => $value->date_value?->format('Y-m-d'),
                        'boolean' => $value->boolean_value,
                        default => null,
                    };

                    return [
                        'key' => $value->field_key_snapshot,
                        'label' => $value->field_label_snapshot,
                        'type' => $value->data_type_snapshot,
                        'unit' => $value->unit_snapshot,
                        'value' => $stored,
                    ];
                })
                ->all(),
            'created_at' => $entry->created_at?->toISOString(),
            'updated_at' => $entry->updated_at?->toISOString(),
        ];
    }

    private function canEdit(User $actor, TrackerEntry $entry): bool
    {
        try {
            $this->access->authorize($actor, 'tracking', ModuleCapability::Edit, $entry);

            return true;
        } catch (AuthorizationException) {
            return false;
        }
    }

    private function nullableTrim(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
