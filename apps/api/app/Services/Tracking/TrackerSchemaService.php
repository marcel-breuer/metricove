<?php

namespace App\Services\Tracking;

use App\Enums\ModuleCapability;
use App\Models\TrackerDefinition;
use App\Models\TrackerField;
use App\Models\TrackerSchemaRevision;
use App\Models\User;
use App\Services\Modules\ModuleAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TrackerSchemaService
{
    public function __construct(private readonly ModuleAccessService $access) {}

    /** @return list<array<string, mixed>> */
    public function index(User $actor): array
    {
        $this->access->authorize($actor, 'tracking', ModuleCapability::View);

        return $this->visibleDefinitions($actor, ModuleCapability::View)
            ->with(['fields' => fn (HasMany $query) => $query->whereNull('retired_version')->orderBy('sort_order')])
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (TrackerDefinition $definition): array => $this->present($actor, $definition))
            ->all();
    }

    /** @return array<string, mixed> */
    public function show(User $actor, int $trackerId): array
    {
        $definition = $this->findVisible($actor, $trackerId, ModuleCapability::View);
        $definition->load([
            'fields' => fn (HasMany $query) => $query->orderBy('created_version')->orderBy('sort_order'),
            'revisions' => fn (HasMany $query) => $query->orderByDesc('version')->limit(20),
        ]);

        return [
            'tracker' => $this->present($actor, $definition, includeRetired: true),
            'schema_revisions' => $definition->revisions
                ->map(fn (TrackerSchemaRevision $revision): array => [
                    'version' => $revision->version,
                    'schema' => $revision->schema_snapshot,
                    'created_at' => $revision->created_at?->toISOString(),
                ])
                ->all(),
        ];
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(User $actor, array $data): array
    {
        return DB::transaction(function () use ($actor, $data): array {
            $currentActor = User::query()->whereKey($actor->id)->lockForUpdate()->first();

            if (! $currentActor instanceof User || ! $currentActor->is_active) {
                throw new AuthorizationException('An active household member is required.');
            }

            $shared = $data['visibility'] === 'household';

            if ($shared) {
                if (! $currentActor->is_admin) {
                    throw new AuthorizationException('Only an active administrator can create a household tracker.');
                }

                $this->access->authorize($currentActor, 'tracking', ModuleCapability::Manage);
            } else {
                $this->access->authorize($currentActor, 'tracking', ModuleCapability::Create);
            }

            $definition = TrackerDefinition::query()->create([
                'created_by_user_id' => $currentActor->id,
                'owner_user_id' => $shared ? null : $currentActor->id,
                'name' => trim($data['name']),
                'description' => $this->nullableTrim($data['description'] ?? null),
                'is_private' => ! $shared,
                'schema_version' => 1,
            ]);

            $this->replaceActiveFields($definition, $data['fields'], 1);
            $this->recordRevision($definition, $currentActor);

            return $this->present($currentActor, $definition->fresh('fields'));
        }, 5);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(User $actor, int $trackerId, array $data): array
    {
        return DB::transaction(function () use ($actor, $trackerId, $data): array {
            $definition = TrackerDefinition::query()
                ->whereKey($trackerId)
                ->lockForUpdate()
                ->firstOrFail();
            $this->access->authorize($actor, 'tracking', ModuleCapability::Manage, $definition);

            if ($definition->schema_version !== (int) $data['schema_version']) {
                abort(409, 'The tracker schema changed. Reload it before saving these changes.');
            }

            $nextVersion = $definition->schema_version + 1;
            $activeFields = $definition->fields()
                ->whereNull('retired_version')
                ->orderBy('sort_order')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $submittedIds = [];
            $errors = [];

            foreach (array_values($data['fields']) as $order => $fieldData) {
                $fieldId = isset($fieldData['id']) ? (int) $fieldData['id'] : null;

                if ($fieldId !== null && ! $activeFields->has($fieldId)) {
                    $errors['fields.'.$order.'.id'][] = 'This field is not part of the current tracker schema.';

                    continue;
                }

                $values = $this->normalizedField($fieldData);

                if ($fieldId === null) {
                    $definition->fields()->create([
                        ...$values,
                        'field_key' => (string) Str::ulid(),
                        'sort_order' => $order,
                        'created_version' => $nextVersion,
                    ]);

                    continue;
                }

                $field = $activeFields->get($fieldId);
                $submittedIds[$fieldId] = true;

                if ($field->entryValues()->exists()
                    && ($field->data_type !== $values['data_type'] || $field->unit !== $values['unit'])) {
                    $errors['fields.'.$order.'.type'][] = 'A field with saved values cannot change its type or unit. Retire it and add a new field instead.';

                    continue;
                }

                $field->fill([...$values, 'sort_order' => $order])->save();
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            foreach ($activeFields as $fieldId => $field) {
                if (! isset($submittedIds[$fieldId])) {
                    $field->forceFill(['retired_version' => $nextVersion])->save();
                }
            }

            $definition->forceFill([
                'name' => trim($data['name']),
                'description' => $this->nullableTrim($data['description'] ?? null),
                'schema_version' => $nextVersion,
            ])->save();
            $this->recordRevision($definition, $actor);

            return $this->present(
                $actor,
                $definition->fresh(['fields' => fn (HasMany $query) => $query->whereNull('retired_version')->orderBy('sort_order')]),
            );
        }, 5);
    }

    public function findVisible(User $actor, int $trackerId, ModuleCapability $capability): TrackerDefinition
    {
        $definition = $this->visibleDefinitions($actor, $capability)
            ->whereKey($trackerId)
            ->firstOrFail();
        $this->access->authorize($actor, 'tracking', $capability, $definition);

        return $definition;
    }

    private function visibleDefinitions(User $actor, ModuleCapability $capability): Builder
    {
        $morphType = (new TrackerDefinition)->getMorphClass();

        return TrackerDefinition::query()->where(function (Builder $query) use ($actor, $capability, $morphType): void {
            $query->where('is_private', false)
                ->orWhere('owner_user_id', $actor->id)
                ->orWhereExists(function (QueryBuilder $permissions) use ($actor, $capability, $morphType): void {
                    $permissions->selectRaw('1')
                        ->from('resource_user_permissions')
                        ->whereColumn('resource_user_permissions.resource_id', 'tracker_definitions.id')
                        ->where('resource_user_permissions.resource_type', $morphType)
                        ->where('resource_user_permissions.user_id', $actor->id)
                        ->where('resource_user_permissions.capability', $capability->value);
                });
        });
    }

    /** @return array<string, mixed> */
    private function present(User $actor, TrackerDefinition $definition, bool $includeRetired = false): array
    {
        if (! $definition->relationLoaded('fields')) {
            $definition->load([
                'fields' => fn (HasMany $query) => $includeRetired
                    ? $query->orderBy('created_version')->orderBy('sort_order')
                    : $query->whereNull('retired_version')->orderBy('sort_order'),
            ]);
        }

        return [
            'id' => $definition->id,
            'name' => $definition->name,
            'description' => $definition->description,
            'visibility' => $definition->is_private ? 'private' : 'household',
            'schema_version' => $definition->schema_version,
            'can_manage_schema' => $this->canAuthorize($actor, ModuleCapability::Manage, $definition),
            'fields' => $definition->fields
                ->map(fn (TrackerField $field): array => $this->presentField($field))
                ->all(),
            'created_at' => $definition->created_at?->toISOString(),
            'updated_at' => $definition->updated_at?->toISOString(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentField(TrackerField $field): array
    {
        return [
            'id' => $field->id,
            'key' => $field->field_key,
            'label' => $field->label,
            'type' => $field->data_type,
            'unit' => $field->unit,
            'required' => $field->is_required,
            'minimum' => $field->minimum,
            'maximum' => $field->maximum,
            'active' => $field->retired_version === null,
            'created_version' => $field->created_version,
            'retired_version' => $field->retired_version,
            'sort_order' => $field->sort_order,
        ];
    }

    private function replaceActiveFields(TrackerDefinition $definition, array $fields, int $version): void
    {
        foreach (array_values($fields) as $order => $field) {
            $definition->fields()->create([
                ...$this->normalizedField($field),
                'field_key' => (string) Str::ulid(),
                'sort_order' => $order,
                'created_version' => $version,
            ]);
        }
    }

    /** @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    private function normalizedField(array $field): array
    {
        return [
            'label' => trim($field['label']),
            'data_type' => $field['type'],
            'unit' => $field['type'] === 'number' ? trim($field['unit']) : null,
            'is_required' => filter_var($field['is_required'], FILTER_VALIDATE_BOOLEAN),
            'minimum' => $field['type'] === 'number' ? DecimalValue::normalize($field['minimum'] ?? null) : null,
            'maximum' => $field['type'] === 'number' ? DecimalValue::normalize($field['maximum'] ?? null) : null,
        ];
    }

    private function recordRevision(TrackerDefinition $definition, User $actor): void
    {
        $fields = $definition->fields()
            ->whereNull('retired_version')
            ->orderBy('sort_order')
            ->get()
            ->map(fn (TrackerField $field): array => [
                'key' => $field->field_key,
                'label' => $field->label,
                'type' => $field->data_type,
                'unit' => $field->unit,
                'required' => $field->is_required,
                'minimum' => $field->minimum,
                'maximum' => $field->maximum,
            ])
            ->all();

        TrackerSchemaRevision::query()->create([
            'tracker_definition_id' => $definition->id,
            'changed_by_user_id' => $actor->id,
            'version' => $definition->schema_version,
            'schema_snapshot' => [
                'name' => $definition->name,
                'description' => $definition->description,
                'fields' => $fields,
            ],
        ]);
    }

    private function canAuthorize(User $actor, ModuleCapability $capability, TrackerDefinition $definition): bool
    {
        try {
            $this->access->authorize($actor, 'tracking', $capability, $definition);

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
