<?php

namespace App\Services\Utilities;

use App\Enums\ModuleCapability;
use App\Models\User;
use App\Models\UtilityMeter;
use App\Models\UtilityReading;
use App\Services\Modules\ModuleAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\LazyCollection;
use Illuminate\Validation\ValidationException;
use Throwable;

class UtilityService
{
    private const MODULE = 'utilities';

    private const METER_TYPES = [
        'electricity' => ['label' => 'Electricity', 'unit' => 'kWh'],
        'gas' => ['label' => 'Gas', 'unit' => 'm³'],
        'water' => ['label' => 'Water', 'unit' => 'm³'],
    ];

    public function __construct(private readonly ModuleAccessService $access) {}

    /** @return list<array<string, mixed>> */
    public function index(User $actor): array
    {
        $this->access->authorize($actor, self::MODULE, ModuleCapability::View);

        return $this->visibleMeters($actor)
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(fn (UtilityMeter $meter): array => $this->presentMeter($meter))
            ->all();
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createMeter(User $actor, array $data): array
    {
        $visibility = $data['visibility'];
        $isPrivate = $visibility === 'private';
        $capability = $isPrivate ? ModuleCapability::Create : ModuleCapability::Manage;
        $type = self::METER_TYPES[$data['meter_type']];
        $meter = DB::transaction(function () use ($actor, $data, $type, $isPrivate, $capability): UtilityMeter {
            if (! $isPrivate) {
                $activeAdmin = User::query()
                    ->whereKey($actor->id)
                    ->where('is_admin', true)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->first();

                if (! $activeAdmin instanceof User) {
                    throw new AuthorizationException('Only an active household administrator can create a shared meter.');
                }
            }

            $this->access->authorize($actor, self::MODULE, $capability);
            $meter = UtilityMeter::query()->create([
                'owner_user_id' => $isPrivate ? $actor->id : null,
                'created_by_user_id' => $actor->id,
                'name' => trim($data['name']),
                'meter_type' => $data['meter_type'],
                'unit' => $type['unit'],
                'description' => $this->nullableTrim($data['description'] ?? null),
                'is_private' => $isPrivate,
                'alert_threshold' => $this->decimalOrNull($data['alert_threshold'] ?? null),
            ]);

            $this->access->authorize($actor, self::MODULE, $capability, $meter);

            return $meter;
        }, 3);

        return $this->presentMeter($meter);
    }

    /** @return array{meter: array<string, mixed>, readings: list<array<string, mixed>>} */
    public function show(User $actor, int $meterId): array
    {
        $meter = $this->findVisibleMeter($actor, $meterId, ModuleCapability::View);

        $recent = $meter->readings()
            ->withCount('revisions')
            ->orderByDesc('reading_date')
            ->orderByDesc('id')
            ->limit(201)
            ->get()
            ->reverse()
            ->values();
        $context = $recent->count() > 200 ? $recent->shift() : null;
        $candidates = $context instanceof UtilityReading ? collect([$context])->concat($recent) : $recent;
        $previous = null;
        $readings = [];

        foreach ($candidates as $reading) {
            try {
                $this->access->authorize($actor, self::MODULE, ModuleCapability::View, $reading);
            } catch (AuthorizationException) {
                $previous = null;

                continue;
            }

            if ($reading === $context) {
                $previous = $reading;

                continue;
            }

            $readings[] = $this->presentReading($reading, $meter, $previous);
            $previous = $reading;
        }

        return ['meter' => $this->presentMeter($meter), 'readings' => $readings];
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateMeter(User $actor, int $meterId, array $data): array
    {
        $this->findVisibleMeter($actor, $meterId, ModuleCapability::Manage);

        $meter = DB::transaction(function () use ($actor, $meterId, $data): UtilityMeter {
            $meter = UtilityMeter::query()->whereKey($meterId)->lockForUpdate()->firstOrFail();
            $this->access->authorize($actor, self::MODULE, ModuleCapability::Manage, $meter);

            if (array_key_exists('name', $data)) {
                $meter->name = trim($data['name']);
            }

            if (array_key_exists('alert_threshold', $data)) {
                $meter->alert_threshold = $this->decimalOrNull($data['alert_threshold']);
            }

            $meter->save();

            return $meter->fresh();
        }, 3);

        return $this->presentMeter($meter);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createReading(User $actor, int $meterId, array $data): array
    {
        $meter = $this->findVisibleMeter($actor, $meterId, ModuleCapability::Create);
        $value = DecimalValue::normalize($data['value']);

        if ($value === null) {
            throw ValidationException::withMessages(['value' => 'Enter a valid non-negative decimal reading.']);
        }

        $photoPath = $this->storePhoto($data['photo'] ?? null, $meterId);

        try {
            $reading = DB::transaction(function () use ($actor, $meterId, $data, $value, $photoPath): UtilityReading {
                $meter = UtilityMeter::query()->whereKey($meterId)->lockForUpdate()->firstOrFail();
                $this->access->authorize($actor, self::MODULE, ModuleCapability::Create, $meter);
                $this->assertChronology($meter, $data['reading_date'], $value);

                $reading = $meter->readings()->create([
                    'owner_user_id' => $meter->owner_user_id,
                    'created_by_user_id' => $actor->id,
                    'is_private' => $meter->is_private,
                    'reading_date' => $data['reading_date'],
                    'value' => $value,
                    'note' => $this->nullableTrim($data['note'] ?? null),
                    'photo_path' => $photoPath,
                ]);

                $this->access->authorize($actor, self::MODULE, ModuleCapability::Create, $reading);

                return $reading;
            }, 3);
        } catch (Throwable $exception) {
            if ($photoPath !== null) {
                Storage::disk('local')->delete($photoPath);
            }

            throw $exception;
        }

        $previous = UtilityReading::query()
            ->where('utility_meter_id', $meterId)
            ->where('reading_date', '<', $reading->reading_date->format('Y-m-d'))
            ->orderByDesc('reading_date')
            ->first();

        return $this->presentReading($reading, $meter, $previous);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function correctReading(User $actor, int $readingId, array $data): array
    {
        $reading = $this->findVisibleReading($actor, $readingId, ModuleCapability::Edit);
        $meter = $this->findVisibleMeter($actor, $reading->utility_meter_id, ModuleCapability::Edit);
        $value = DecimalValue::normalize($data['value']);

        if ($value === null) {
            throw ValidationException::withMessages(['value' => 'Enter a valid non-negative decimal reading.']);
        }

        $corrected = DB::transaction(function () use ($actor, $readingId, $data, $value): UtilityReading {
            $candidate = UtilityReading::query()->findOrFail($readingId);
            $meter = UtilityMeter::query()->whereKey($candidate->utility_meter_id)->lockForUpdate()->firstOrFail();
            $reading = UtilityReading::query()->whereKey($readingId)->lockForUpdate()->firstOrFail();
            $this->access->authorize($actor, self::MODULE, ModuleCapability::Edit, $meter);
            $this->access->authorize($actor, self::MODULE, ModuleCapability::Edit, $reading);

            $date = $data['reading_date'];
            $note = $this->nullableTrim($data['note'] ?? null);
            $this->assertChronology($meter, $date, $value, $reading->id);

            if ($date === $reading->reading_date->format('Y-m-d')
                && DecimalValue::compare($value, (string) $reading->value) === 0
                && $note === $reading->note) {
                throw ValidationException::withMessages(['reason' => 'Change the reading before saving a correction.']);
            }

            $reading->revisions()->create([
                'changed_by_user_id' => $actor->id,
                'old_reading_date' => $reading->reading_date->format('Y-m-d'),
                'new_reading_date' => $date,
                'old_value' => $reading->value,
                'new_value' => $value,
                'old_note' => $reading->note,
                'new_note' => $note,
                'reason' => trim($data['reason']),
            ]);

            $reading->forceFill(['reading_date' => $date, 'value' => $value, 'note' => $note])->save();

            return $reading->fresh()->loadCount('revisions');
        }, 3);

        $meter->refresh();
        $previous = UtilityReading::query()
            ->where('utility_meter_id', $meter->id)
            ->where('reading_date', '<', $corrected->reading_date->format('Y-m-d'))
            ->orderByDesc('reading_date')
            ->first();

        return $this->presentReading($corrected, $meter, $previous);
    }

    /** @return list<array<string, mixed>> */
    public function revisions(User $actor, int $readingId): array
    {
        $reading = $this->findVisibleReading($actor, $readingId, ModuleCapability::View);
        $this->findVisibleMeter($actor, $reading->utility_meter_id, ModuleCapability::View);

        return $reading->revisions()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn ($revision): array => [
                'id' => $revision->id,
                'old_reading_date' => $revision->old_reading_date->format('Y-m-d'),
                'new_reading_date' => $revision->new_reading_date->format('Y-m-d'),
                'old_value' => $revision->old_value,
                'new_value' => $revision->new_value,
                'old_note' => $revision->old_note,
                'new_note' => $revision->new_note,
                'reason' => $revision->reason,
                'changed_at' => $revision->created_at?->toISOString(),
            ])->all();
    }

    public function photo(User $actor, int $readingId): string
    {
        $reading = $this->findVisibleReading($actor, $readingId, ModuleCapability::View);
        $this->findVisibleMeter($actor, $reading->utility_meter_id, ModuleCapability::View);

        abort_if($reading->photo_path === null || ! Storage::disk('local')->exists($reading->photo_path), 404);

        return Storage::disk('local')->path($reading->photo_path);
    }

    /**
     * Return the full visible reading history for an authorized import target.
     * Imports require both meter-level view and create access so chronology checks
     * never need to inspect a reading that the member cannot view.
     *
     * @return array{meter: UtilityMeter, readings: list<UtilityReading>}
     */
    public function importContext(User $actor, int $meterId, bool $lock = false): array
    {
        $query = UtilityMeter::query()->whereKey($meterId);
        if ($lock) {
            $query->lockForUpdate();
        }
        $meter = $query->firstOrFail();
        $this->access->authorize($actor, self::MODULE, ModuleCapability::View, $meter);
        $this->access->authorize($actor, self::MODULE, ModuleCapability::Create, $meter);

        $allReadingsCount = $meter->readings()->count();
        $visibleReadings = $this->visibleReadings($actor, $meter)
            ->orderBy('reading_date')
            ->orderBy('id');
        $readings = $visibleReadings->get();

        if ($readings->count() !== $allReadingsCount) {
            throw new AuthorizationException('All readings on this meter must be visible to import data.');
        }

        return ['meter' => $meter, 'readings' => $readings->all()];
    }

    public function authorizeMeterForReading(User $actor, int $meterId): UtilityMeter
    {
        $meter = $this->findVisibleMeter($actor, $meterId, ModuleCapability::View);
        $this->access->authorize($actor, self::MODULE, ModuleCapability::Create, $meter);

        return $meter;
    }

    /** @return LazyCollection<int, UtilityReading> */
    public function exportableReadings(User $actor, int $meterId): LazyCollection
    {
        $meter = $this->findVisibleMeter($actor, $meterId, ModuleCapability::View);

        return $this->visibleReadings($actor, $meter)
            ->orderBy('reading_date')
            ->orderBy('id')
            ->cursor();
    }

    private function visibleMeters(User $actor): Builder
    {
        $morphType = (new UtilityMeter)->getMorphClass();

        return UtilityMeter::query()->where(function (Builder $query) use ($actor, $morphType): void {
            $query->where('is_private', false)
                ->orWhere('owner_user_id', $actor->id)
                ->orWhereExists(function (QueryBuilder $permissions) use ($actor, $morphType): void {
                    $permissions->selectRaw('1')
                        ->from('resource_user_permissions')
                        ->whereColumn('resource_user_permissions.resource_id', 'utility_meters.id')
                        ->where('resource_user_permissions.resource_type', $morphType)
                        ->where('resource_user_permissions.user_id', $actor->id)
                        ->where('resource_user_permissions.capability', ModuleCapability::View->value);
                });
        });
    }

    private function findVisibleMeter(User $actor, int $meterId, ModuleCapability $capability): UtilityMeter
    {
        $morphType = (new UtilityMeter)->getMorphClass();
        $meter = UtilityMeter::query()->whereKey($meterId)->where(function (Builder $query) use ($actor, $capability, $morphType): void {
            $query->where('is_private', false)
                ->orWhere('owner_user_id', $actor->id)
                ->orWhereExists(function (QueryBuilder $permissions) use ($actor, $capability, $morphType): void {
                    $permissions->selectRaw('1')
                        ->from('resource_user_permissions')
                        ->whereColumn('resource_user_permissions.resource_id', 'utility_meters.id')
                        ->where('resource_user_permissions.resource_type', $morphType)
                        ->where('resource_user_permissions.user_id', $actor->id)
                        ->where('resource_user_permissions.capability', $capability->value);
                });
        })->firstOrFail();

        $this->access->authorize($actor, self::MODULE, $capability, $meter);

        return $meter;
    }

    private function findVisibleReading(User $actor, int $readingId, ModuleCapability $capability): UtilityReading
    {
        $morphType = (new UtilityReading)->getMorphClass();
        $reading = UtilityReading::query()->whereKey($readingId)->where(function (Builder $query) use ($actor, $capability, $morphType): void {
            $query->where('is_private', false)
                ->orWhere('owner_user_id', $actor->id)
                ->orWhereExists(function (QueryBuilder $permissions) use ($actor, $capability, $morphType): void {
                    $permissions->selectRaw('1')
                        ->from('resource_user_permissions')
                        ->whereColumn('resource_user_permissions.resource_id', 'utility_readings.id')
                        ->where('resource_user_permissions.resource_type', $morphType)
                        ->where('resource_user_permissions.user_id', $actor->id)
                        ->where('resource_user_permissions.capability', $capability->value);
                });
        })->firstOrFail();

        $this->access->authorize($actor, self::MODULE, $capability, $reading);

        return $reading;
    }

    private function visibleReadings(User $actor, UtilityMeter $meter): HasMany
    {
        $morphType = (new UtilityReading)->getMorphClass();

        return $meter->readings()->where(function (Builder $query) use ($actor, $morphType): void {
            $query->where('is_private', false)
                ->orWhere('owner_user_id', $actor->id)
                ->orWhereExists(function (QueryBuilder $permissions) use ($actor, $morphType): void {
                    $permissions->selectRaw('1')
                        ->from('resource_user_permissions')
                        ->whereColumn('resource_user_permissions.resource_id', 'utility_readings.id')
                        ->where('resource_user_permissions.resource_type', $morphType)
                        ->where('resource_user_permissions.user_id', $actor->id)
                        ->where('resource_user_permissions.capability', ModuleCapability::View->value);
                });
        });
    }

    private function assertChronology(UtilityMeter $meter, string $date, string $value, ?int $exceptReadingId = null): void
    {
        $readings = $meter->readings()->when($exceptReadingId !== null, fn (Builder $query) => $query->whereKeyNot($exceptReadingId));

        if ($readings->where('reading_date', $date)->exists()) {
            throw ValidationException::withMessages(['reading_date' => 'This meter already has a reading for that date.']);
        }

        $previous = $meter->readings()
            ->when($exceptReadingId !== null, fn (Builder $query) => $query->whereKeyNot($exceptReadingId))
            ->where('reading_date', '<', $date)
            ->orderByDesc('reading_date')
            ->lockForUpdate()
            ->first();
        $next = $meter->readings()
            ->when($exceptReadingId !== null, fn (Builder $query) => $query->whereKeyNot($exceptReadingId))
            ->where('reading_date', '>', $date)
            ->orderBy('reading_date')
            ->lockForUpdate()
            ->first();

        if ($previous instanceof UtilityReading && DecimalValue::compare((string) $previous->value, $value) > 0) {
            throw ValidationException::withMessages(['value' => 'A reading cannot be lower than the previous chronological reading.']);
        }

        if ($next instanceof UtilityReading && DecimalValue::compare($value, (string) $next->value) > 0) {
            throw ValidationException::withMessages(['value' => 'A reading cannot be higher than the next chronological reading.']);
        }
    }

    /** @return array<string, mixed> */
    private function presentMeter(UtilityMeter $meter): array
    {
        $type = self::METER_TYPES[$meter->meter_type];

        return [
            'id' => $meter->id,
            'name' => $meter->name,
            'meter_type' => $meter->meter_type,
            'meter_label' => $type['label'],
            'unit' => $meter->unit,
            'description' => $meter->description,
            'visibility' => $meter->is_private ? 'private' : 'household',
            'alert_threshold' => $meter->alert_threshold,
            'created_at' => $meter->created_at?->toISOString(),
            'updated_at' => $meter->updated_at?->toISOString(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentReading(UtilityReading $reading, UtilityMeter $meter, ?UtilityReading $previous): array
    {
        $usage = $previous === null ? null : DecimalValue::subtract((string) $reading->value, (string) $previous->value);
        $days = $previous === null
            ? null
            : CarbonImmutable::parse($previous->reading_date->format('Y-m-d'))->diffInDays(CarbonImmutable::parse($reading->reading_date->format('Y-m-d')));

        return [
            'id' => $reading->id,
            'reading_date' => $reading->reading_date->format('Y-m-d'),
            'value' => $reading->value,
            'unit' => $meter->unit,
            'note' => $reading->note,
            'photo_url' => $reading->photo_path === null ? null : '/api/utilities/readings/'.$reading->id.'/photo',
            'consumption_since_previous' => $usage,
            'days_since_previous' => $days,
            'alert' => $usage !== null
                && $meter->alert_threshold !== null
                && DecimalValue::compare($usage, (string) $meter->alert_threshold) > 0,
            'revision_count' => (int) ($reading->revisions_count ?? 0),
            'created_at' => $reading->created_at?->toISOString(),
            'updated_at' => $reading->updated_at?->toISOString(),
        ];
    }

    private function storePhoto(mixed $photo, int $meterId): ?string
    {
        if (! $photo instanceof UploadedFile) {
            return null;
        }

        $path = $photo->store('utilities/'.$meterId, 'local');

        if (! is_string($path)) {
            throw new \RuntimeException('The private meter photo could not be stored.');
        }

        return $path;
    }

    private function decimalOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return DecimalValue::normalize($value)
            ?? throw ValidationException::withMessages(['alert_threshold' => 'Enter a valid non-negative decimal threshold.']);
    }

    private function nullableTrim(?string $value): ?string
    {
        $trimmed = trim($value ?? '');

        return $trimmed === '' ? null : $trimmed;
    }
}
