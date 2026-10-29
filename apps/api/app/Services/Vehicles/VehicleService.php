<?php

namespace App\Services\Vehicles;

use App\Enums\ModuleCapability;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCost;
use App\Models\VehicleInsurancePolicy;
use App\Models\VehicleMaintenanceRecord;
use App\Models\VehicleOdometerReading;
use App\Services\Modules\ModuleAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VehicleService
{
    private const MODULE = 'vehicles';

    public function __construct(private readonly ModuleAccessService $access) {}

    /** @return list<array<string, mixed>> */
    public function index(User $actor): array
    {
        $this->access->authorize($actor, self::MODULE, ModuleCapability::View);

        return $this->visibleVehicles($actor, ModuleCapability::View)
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->filter(fn (Vehicle $vehicle): bool => $this->canAccess($actor, ModuleCapability::View, $vehicle))
            ->map(fn (Vehicle $vehicle): array => $this->presentVehicle($vehicle))
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(User $actor, array $data): array
    {
        $isPrivate = $data['visibility'] === 'private';
        $capability = $isPrivate ? ModuleCapability::Create : ModuleCapability::Manage;
        $vehicle = DB::transaction(function () use ($actor, $data, $isPrivate, $capability): Vehicle {
            if (! $isPrivate) {
                $activeAdmin = User::query()
                    ->whereKey($actor->id)
                    ->where('is_admin', true)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->first();

                if (! $activeAdmin instanceof User) {
                    throw new AuthorizationException('Only an active household administrator can create a shared vehicle.');
                }
            }

            $this->access->authorize($actor, self::MODULE, $capability);
            $vehicle = Vehicle::query()->create([
                'owner_user_id' => $isPrivate ? $actor->id : null,
                'created_by_user_id' => $actor->id,
                'name' => trim($data['name']),
                'make' => trim($data['make']),
                'model' => trim($data['model']),
                'model_year' => $data['model_year'] ?? null,
                'fuel_type' => $data['fuel_type'],
                'odometer_unit' => $data['odometer_unit'],
                'description' => $this->nullableTrim($data['description'] ?? null),
                'is_private' => $isPrivate,
            ]);
            $this->access->authorize($actor, self::MODULE, $capability, $vehicle);

            return $vehicle;
        }, 3);

        return $this->presentVehicle($vehicle);
    }

    /** @return array{vehicle: array<string, mixed>, odometer_readings: list<array<string, mixed>>, has_more_odometer_readings: bool, maintenance_records: list<array<string, mixed>>, insurance_policies: list<array<string, mixed>>, costs: list<array<string, mixed>>} */
    public function show(User $actor, int $vehicleId): array
    {
        $vehicle = $this->findVisibleVehicle($actor, $vehicleId, ModuleCapability::View);
        $odometerHistory = $this->odometerHistory($actor, $vehicleId);

        return [
            'vehicle' => $this->presentVehicle($vehicle),
            ...$odometerHistory,
            'maintenance_records' => $this->visibleChildren($actor, $vehicle, $vehicle->maintenanceRecords()->limit(100)->get()),
            'insurance_policies' => $this->visibleChildren($actor, $vehicle, $vehicle->insurancePolicies()->limit(100)->get()),
            'costs' => $this->visibleChildren($actor, $vehicle, $vehicle->costs()->limit(100)->get()),
        ];
    }

    /** @return array{odometer_readings: list<array<string, mixed>>, has_more_odometer_readings: bool} */
    public function odometerHistory(
        User $actor,
        int $vehicleId,
        int $limit = 30,
        ?string $beforeReadingDate = null,
        ?int $beforeId = null,
    ): array {
        $vehicle = $this->findVisibleVehicle($actor, $vehicleId, ModuleCapability::View);
        $query = $this->visibleResources(
            $actor,
            VehicleOdometerReading::class,
            'vehicle_odometer_readings',
            ModuleCapability::View,
            VehicleOdometerReading::query()->where('vehicle_id', $vehicle->id),
        );

        if ($beforeReadingDate !== null && $beforeId !== null) {
            $query->where(function (Builder $cursor) use ($beforeReadingDate, $beforeId): void {
                $cursor->where('reading_date', '<', $beforeReadingDate)
                    ->orWhere(function (Builder $sameDate) use ($beforeReadingDate, $beforeId): void {
                        $sameDate->where('reading_date', $beforeReadingDate)->where('id', '<', $beforeId);
                    });
            });
        }

        $page = $query->withCount('revisions')
            ->orderByDesc('reading_date')
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get();
        $hasMore = $page->count() > $limit;
        $readings = $page->take($limit)->reverse()->values();
        $presented = [];

        foreach ($readings as $reading) {
            /** @var VehicleOdometerReading $reading */
            $readingDate = CarbonImmutable::parse($reading->reading_date)->format('Y-m-d');
            /** @var VehicleOdometerReading|null $previous */
            $previous = $vehicle->odometerReadings()
                ->where(function (Builder $preceding) use ($reading, $readingDate): void {
                    $preceding->where('reading_date', '<', $readingDate)
                        ->orWhere(function (Builder $sameDate) use ($reading, $readingDate): void {
                            $sameDate->where('reading_date', $readingDate)->where('id', '<', $reading->id);
                        });
                })
                ->orderByDesc('reading_date')
                ->orderByDesc('id')
                ->first();
            if ($previous instanceof VehicleOdometerReading && ! $this->canAccess($actor, ModuleCapability::View, $previous)) {
                $previous = null;
            }

            if ($this->canAccess($actor, ModuleCapability::View, $reading)) {
                $presented[] = $this->presentOdometer($reading, $vehicle, $previous);
            }
        }

        return ['odometer_readings' => $presented, 'has_more_odometer_readings' => $hasMore];
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(User $actor, int $vehicleId, array $data): array
    {
        $this->findVisibleVehicle($actor, $vehicleId, ModuleCapability::Manage);
        $vehicle = DB::transaction(function () use ($actor, $vehicleId, $data): Vehicle {
            $vehicle = Vehicle::query()->whereKey($vehicleId)->lockForUpdate()->firstOrFail();
            $this->access->authorize($actor, self::MODULE, ModuleCapability::Manage, $vehicle);

            if (array_key_exists('odometer_unit', $data)
                && $data['odometer_unit'] !== $vehicle->odometer_unit
                && ($vehicle->odometerReadings()->exists()
                    || $vehicle->maintenanceRecords()->whereNotNull('odometer_value')->exists())) {
                throw ValidationException::withMessages(['odometer_unit' => 'The distance unit cannot change after odometer readings are recorded.']);
            }

            foreach (['name', 'make', 'model', 'fuel_type', 'odometer_unit', 'description'] as $field) {
                if (array_key_exists($field, $data)) {
                    $vehicle->{$field} = $field === 'description' ? $this->nullableTrim($data[$field]) : trim($data[$field]);
                }
            }

            if (array_key_exists('model_year', $data)) {
                $vehicle->model_year = $data['model_year'];
            }

            $vehicle->save();

            return $vehicle->fresh();
        }, 3);

        return $this->presentVehicle($vehicle);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function addOdometerReading(User $actor, int $vehicleId, array $data): array
    {
        $vehicle = $this->findVisibleVehicle($actor, $vehicleId, ModuleCapability::Create);
        $value = VehicleDecimalValue::distance($data['value']);

        if ($value === null) {
            throw ValidationException::withMessages(['value' => 'Enter a non-negative distance with up to 17 whole and 3 fractional digits.']);
        }

        $reading = DB::transaction(function () use ($actor, $vehicleId, $data, $value): VehicleOdometerReading {
            $vehicle = Vehicle::query()->whereKey($vehicleId)->lockForUpdate()->firstOrFail();
            $this->access->authorize($actor, self::MODULE, ModuleCapability::Create, $vehicle);
            $this->assertOdometerChronology($vehicle, $data['reading_date'], $value);
            $reading = $vehicle->odometerReadings()->create([
                'owner_user_id' => $vehicle->owner_user_id,
                'created_by_user_id' => $actor->id,
                'is_private' => $vehicle->is_private,
                'reading_date' => $data['reading_date'],
                'value' => $value,
                'note' => $this->nullableTrim($data['note'] ?? null),
            ]);
            $this->access->authorize($actor, self::MODULE, ModuleCapability::Create, $reading);

            return $reading;
        }, 3);

        $vehicle->refresh();

        return $this->presentOdometer($reading, $vehicle, null);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function correctOdometerReading(User $actor, int $readingId, array $data): array
    {
        $candidate = $this->findVisibleOdometer($actor, $readingId, ModuleCapability::Edit);
        $this->findVisibleVehicle($actor, $candidate->vehicle_id, ModuleCapability::Edit);
        $value = VehicleDecimalValue::distance($data['value']);

        if ($value === null) {
            throw ValidationException::withMessages(['value' => 'Enter a non-negative distance with up to 17 whole and 3 fractional digits.']);
        }

        $corrected = DB::transaction(function () use ($actor, $readingId, $data, $value): VehicleOdometerReading {
            $candidate = VehicleOdometerReading::query()->findOrFail($readingId);
            $vehicle = Vehicle::query()->whereKey($candidate->vehicle_id)->lockForUpdate()->firstOrFail();
            $reading = VehicleOdometerReading::query()->whereKey($readingId)->lockForUpdate()->firstOrFail();
            $this->access->authorize($actor, self::MODULE, ModuleCapability::Edit, $vehicle);
            $this->access->authorize($actor, self::MODULE, ModuleCapability::Edit, $reading);
            $this->assertOdometerChronology($vehicle, $data['reading_date'], $value, $reading->id);
            $note = $this->nullableTrim($data['note'] ?? null);

            if ($reading->reading_date->format('Y-m-d') === $data['reading_date']
                && VehicleDecimalValue::compareDistance((string) $reading->value, $value) === 0
                && $reading->note === $note) {
                throw ValidationException::withMessages(['reason' => 'Change the reading before saving a correction.']);
            }

            $reading->revisions()->create([
                'changed_by_user_id' => $actor->id,
                'old_reading_date' => $reading->reading_date->format('Y-m-d'),
                'new_reading_date' => $data['reading_date'],
                'old_value' => $reading->value,
                'new_value' => $value,
                'old_note' => $reading->note,
                'new_note' => $note,
                'reason' => $this->nullableTrim($data['reason'] ?? null),
            ]);
            $reading->forceFill(['reading_date' => $data['reading_date'], 'value' => $value, 'note' => $note])->save();

            return $reading->fresh()->loadCount('revisions');
        }, 3);

        return $this->presentOdometer($corrected, $candidate->vehicle, null);
    }

    /** @return list<array<string, mixed>> */
    public function odometerRevisions(User $actor, int $readingId): array
    {
        $reading = $this->findVisibleOdometer($actor, $readingId, ModuleCapability::View);
        $this->findVisibleVehicle($actor, $reading->vehicle_id, ModuleCapability::View);

        return $reading->revisions()->limit(50)->get()->map(fn ($revision): array => [
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

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function addMaintenance(User $actor, int $vehicleId, array $data): array
    {
        $vehicle = $this->findVisibleVehicle($actor, $vehicleId, ModuleCapability::Create);
        $odometerValue = $this->nullableDistance($data['odometer_value'] ?? null);
        $record = DB::transaction(function () use ($actor, $vehicleId, $data, $odometerValue): VehicleMaintenanceRecord {
            $vehicle = Vehicle::query()->whereKey($vehicleId)->lockForUpdate()->firstOrFail();
            $this->access->authorize($actor, self::MODULE, ModuleCapability::Create, $vehicle);
            if ($odometerValue !== null) {
                $this->assertDistanceFitsTimeline($vehicle, $data['occurred_on'], $odometerValue);
            }
            $record = $vehicle->maintenanceRecords()->create([
                'owner_user_id' => $vehicle->owner_user_id,
                'created_by_user_id' => $actor->id,
                'is_private' => $vehicle->is_private,
                'title' => trim($data['title']),
                'service_type' => $data['service_type'],
                'occurred_on' => $data['occurred_on'],
                'next_due_on' => $data['next_due_on'] ?? null,
                'odometer_value' => $odometerValue,
                'note' => $this->nullableTrim($data['note'] ?? null),
            ]);
            $this->access->authorize($actor, self::MODULE, ModuleCapability::Create, $record);

            return $record;
        }, 3);

        $vehicle->refresh();

        return $this->presentMaintenance($record, $vehicle);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function addInsurance(User $actor, int $vehicleId, array $data): array
    {
        $vehicle = $this->findVisibleVehicle($actor, $vehicleId, ModuleCapability::Create);
        $policy = DB::transaction(function () use ($actor, $vehicleId, $data): VehicleInsurancePolicy {
            $vehicle = Vehicle::query()->whereKey($vehicleId)->lockForUpdate()->firstOrFail();
            $this->access->authorize($actor, self::MODULE, ModuleCapability::Create, $vehicle);
            $policy = $vehicle->insurancePolicies()->create([
                'owner_user_id' => $vehicle->owner_user_id,
                'created_by_user_id' => $actor->id,
                'is_private' => $vehicle->is_private,
                'provider' => trim($data['provider']),
                'coverage_type' => trim($data['coverage_type']),
                'valid_from' => $data['valid_from'],
                'valid_until' => $data['valid_until'],
                'note' => $this->nullableTrim($data['note'] ?? null),
            ]);
            $this->access->authorize($actor, self::MODULE, ModuleCapability::Create, $policy);

            return $policy;
        }, 3);

        return $this->presentInsurance($policy);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function addCost(User $actor, int $vehicleId, array $data): array
    {
        $vehicle = $this->findVisibleVehicle($actor, $vehicleId, ModuleCapability::Create);
        $amount = VehicleDecimalValue::money($data['amount']);
        $rawFuelLiters = $data['fuel_liters'] ?? null;
        $rawFuelUnitPrice = $data['fuel_unit_price'] ?? null;
        $rawFuelOdometerValue = $data['fuel_odometer_value'] ?? null;
        $fuelLiters = $rawFuelLiters === null || $rawFuelLiters === '' ? null : VehicleDecimalValue::fuelQuantity($rawFuelLiters);
        $fuelUnitPrice = $rawFuelUnitPrice === null || $rawFuelUnitPrice === '' ? null : VehicleDecimalValue::fuelUnitPrice($rawFuelUnitPrice);
        $fuelOdometerValue = $rawFuelOdometerValue === null || $rawFuelOdometerValue === '' ? null : VehicleDecimalValue::distance($rawFuelOdometerValue);

        if ($amount === null) {
            throw ValidationException::withMessages(['amount' => 'Enter a non-negative amount with up to 16 whole and 2 fractional digits.']);
        }
        if ($rawFuelLiters !== null && $rawFuelLiters !== '' && ($fuelLiters === null || preg_match('/\A0(?:\.0+)?\z/', $fuelLiters) === 1)) {
            throw ValidationException::withMessages(['fuel_liters' => 'Enter a positive fuel quantity with up to 11 whole and 3 fractional digits.']);
        }
        if ($rawFuelUnitPrice !== null && $rawFuelUnitPrice !== '' && ($fuelUnitPrice === null || preg_match('/\A0(?:\.0+)?\z/', $fuelUnitPrice) === 1)) {
            throw ValidationException::withMessages(['fuel_unit_price' => 'Enter a positive unit price with up to 10 whole and 4 fractional digits.']);
        }
        if ($rawFuelOdometerValue !== null && $rawFuelOdometerValue !== '' && $fuelOdometerValue === null) {
            throw ValidationException::withMessages(['fuel_odometer_value' => 'Enter a non-negative odometer value with up to 17 whole and 3 fractional digits.']);
        }
        if (($fuelLiters === null) !== ($fuelUnitPrice === null)) {
            throw ValidationException::withMessages(['fuel_liters' => 'Enter both fuel quantity and unit price, or leave both blank.']);
        }
        if ($data['category'] !== 'fuel' && ($fuelLiters !== null || $fuelUnitPrice !== null || $fuelOdometerValue !== null)) {
            throw ValidationException::withMessages(['category' => 'Fuel details are only available for fuel costs.']);
        }

        $cost = DB::transaction(function () use ($actor, $vehicleId, $data, $amount, $fuelLiters, $fuelUnitPrice, $fuelOdometerValue): VehicleCost {
            $vehicle = Vehicle::query()->whereKey($vehicleId)->lockForUpdate()->firstOrFail();
            $this->access->authorize($actor, self::MODULE, ModuleCapability::Create, $vehicle);
            $cost = $vehicle->costs()->create([
                'owner_user_id' => $vehicle->owner_user_id,
                'created_by_user_id' => $actor->id,
                'is_private' => $vehicle->is_private,
                'category' => $data['category'],
                'amount' => $amount,
                'currency' => strtoupper($data['currency']),
                'period_start' => $data['period_start'],
                'period_end' => $data['period_end'] ?? null,
                'period' => $data['period'],
                'description' => $this->nullableTrim($data['description'] ?? null),
                'fuel_liters' => $fuelLiters,
                'fuel_unit_price' => $fuelUnitPrice,
                'fuel_odometer_value' => $fuelOdometerValue,
            ]);
            $this->access->authorize($actor, self::MODULE, ModuleCapability::Create, $cost);

            return $cost;
        }, 3);

        return $this->presentCost($cost);
    }

    private function visibleVehicles(User $actor, ModuleCapability $capability): Builder
    {
        return $this->visibleResources($actor, Vehicle::class, 'vehicles', $capability, Vehicle::query());
    }

    private function findVisibleVehicle(User $actor, int $vehicleId, ModuleCapability $capability): Vehicle
    {
        $vehicle = $this->visibleVehicles($actor, $capability)->whereKey($vehicleId)->firstOrFail();
        $this->access->authorize($actor, self::MODULE, $capability, $vehicle);

        return $vehicle;
    }

    private function findVisibleOdometer(User $actor, int $readingId, ModuleCapability $capability): VehicleOdometerReading
    {
        $query = $this->visibleResources($actor, VehicleOdometerReading::class, 'vehicle_odometer_readings', $capability, VehicleOdometerReading::query());
        $reading = $query->whereKey($readingId)->firstOrFail();
        $this->access->authorize($actor, self::MODULE, $capability, $reading);

        return $reading;
    }

    /** @param class-string<Model> $modelClass */
    private function visibleResources(User $actor, string $modelClass, string $table, ModuleCapability $capability, Builder $query): Builder
    {
        $morphType = (new $modelClass)->getMorphClass();

        return $query->where(function (Builder $visible) use ($actor, $capability, $morphType, $table): void {
            $visible->where('is_private', false)
                ->orWhere('owner_user_id', $actor->id)
                ->orWhereExists(function (QueryBuilder $permissions) use ($actor, $capability, $morphType, $table): void {
                    $permissions->selectRaw('1')
                        ->from('resource_user_permissions')
                        ->whereColumn('resource_user_permissions.resource_id', $table.'.id')
                        ->where('resource_user_permissions.resource_type', $morphType)
                        ->where('resource_user_permissions.user_id', $actor->id)
                        ->where('resource_user_permissions.capability', $capability->value);
                });
        });
    }

    private function assertOdometerChronology(Vehicle $vehicle, string $date, string $value, ?int $exceptReadingId = null): void
    {
        $readings = $vehicle->odometerReadings()->when($exceptReadingId !== null, fn (Builder $query) => $query->whereKeyNot($exceptReadingId));

        if ($readings->where('reading_date', $date)->exists()) {
            throw ValidationException::withMessages(['reading_date' => 'This vehicle already has an odometer reading for that date.']);
        }

        $this->assertDistanceFitsTimeline($vehicle, $date, $value, $exceptReadingId);
    }

    private function assertDistanceFitsTimeline(Vehicle $vehicle, string $date, string $value, ?int $exceptReadingId = null): void
    {
        $previous = $this->mileageBoundary($vehicle, $date, true, $exceptReadingId);
        $next = $this->mileageBoundary($vehicle, $date, false, $exceptReadingId);

        if ($previous !== null && VehicleDecimalValue::compareDistance($previous, $value) > 0) {
            throw ValidationException::withMessages(['value' => 'An odometer reading cannot be lower than the previous chronological reading.']);
        }

        if ($next !== null && VehicleDecimalValue::compareDistance($value, $next) > 0) {
            throw ValidationException::withMessages(['value' => 'An odometer reading cannot be higher than the next chronological reading.']);
        }
    }

    private function mileageBoundary(Vehicle $vehicle, string $date, bool $before, ?int $exceptReadingId): ?string
    {
        $operator = $before ? '<' : '>';
        $readings = DB::table('vehicle_odometer_readings')
            ->where('vehicle_id', $vehicle->id)
            ->where('reading_date', $operator, $date)
            ->when($exceptReadingId !== null, fn (QueryBuilder $query) => $query->where('id', '<>', $exceptReadingId))
            ->select('value');
        $maintenance = DB::table('vehicle_maintenance_records')
            ->where('vehicle_id', $vehicle->id)
            ->whereNotNull('odometer_value')
            ->where('occurred_on', $operator, $date)
            ->selectRaw('odometer_value AS value');
        $events = $readings->unionAll($maintenance);
        $function = $before ? 'MAX' : 'MIN';
        $boundary = DB::query()->fromSub($events, 'vehicle_mileage_events')->selectRaw("{$function}(value) AS distance")->value('distance');

        return $boundary === null ? null : (string) $boundary;
    }

    /** @param iterable<Model> $records
     * @return list<array<string, mixed>>
     */
    private function visibleChildren(User $actor, Vehicle $vehicle, iterable $records): array
    {
        $visible = [];

        foreach ($records as $record) {
            if (! $this->canAccess($actor, ModuleCapability::View, $record)) {
                continue;
            }

            $visible[] = match (true) {
                $record instanceof VehicleMaintenanceRecord => $this->presentMaintenance($record, $vehicle),
                $record instanceof VehicleInsurancePolicy => $this->presentInsurance($record),
                $record instanceof VehicleCost => $this->presentCost($record),
                default => [],
            };
        }

        return $visible;
    }

    private function canAccess(User $actor, ModuleCapability $capability, Model $resource): bool
    {
        try {
            $this->access->authorize($actor, self::MODULE, $capability, $resource);

            return true;
        } catch (AuthorizationException) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    private function presentVehicle(Vehicle $vehicle): array
    {
        return [
            'id' => $vehicle->id,
            'name' => $vehicle->name,
            'make' => $vehicle->make,
            'model' => $vehicle->model,
            'model_year' => $vehicle->model_year,
            'fuel_type' => $vehicle->fuel_type,
            'odometer_unit' => $vehicle->odometer_unit,
            'description' => $vehicle->description,
            'visibility' => $vehicle->is_private ? 'private' : 'household',
            'created_at' => $vehicle->created_at?->toISOString(),
            'updated_at' => $vehicle->updated_at?->toISOString(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentOdometer(VehicleOdometerReading $reading, Vehicle $vehicle, ?VehicleOdometerReading $previous): array
    {
        $distance = $previous === null ? null : $this->subtractDistance((string) $reading->value, (string) $previous->value);
        $days = $previous === null ? null : CarbonImmutable::parse($previous->reading_date->format('Y-m-d'))->diffInDays(CarbonImmutable::parse($reading->reading_date->format('Y-m-d')));

        return [
            'id' => $reading->id,
            'reading_date' => $reading->reading_date->format('Y-m-d'),
            'value' => $reading->value,
            'unit' => $vehicle->odometer_unit,
            'distance_since_previous' => $distance,
            'days_since_previous' => $days,
            'note' => $reading->note,
            'revision_count' => (int) ($reading->revisions_count ?? 0),
            'created_at' => $reading->created_at?->toISOString(),
            'updated_at' => $reading->updated_at?->toISOString(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentMaintenance(VehicleMaintenanceRecord $record, ?Vehicle $vehicle): array
    {
        return [
            'id' => $record->id,
            'title' => $record->title,
            'service_type' => $record->service_type,
            'occurred_on' => $record->occurred_on->format('Y-m-d'),
            'next_due_on' => $record->next_due_on === null
                ? null
                : CarbonImmutable::parse($record->next_due_on)->format('Y-m-d'),
            'odometer_value' => $record->odometer_value,
            'odometer_unit' => $vehicle?->odometer_unit,
            'note' => $record->note,
        ];
    }

    /** @return array<string, mixed> */
    private function presentInsurance(VehicleInsurancePolicy $policy): array
    {
        return [
            'id' => $policy->id,
            'provider' => $policy->provider,
            'coverage_type' => $policy->coverage_type,
            'valid_from' => $policy->valid_from->format('Y-m-d'),
            'valid_until' => $policy->valid_until->format('Y-m-d'),
            'note' => $policy->note,
            'status' => CarbonImmutable::today()->gte($policy->valid_from) && CarbonImmutable::today()->lte($policy->valid_until)
                ? 'active'
                : ($policy->valid_until->lt(CarbonImmutable::today()) ? 'expired' : 'upcoming'),
        ];
    }

    /** @return array<string, mixed> */
    private function presentCost(VehicleCost $cost): array
    {
        return [
            'id' => $cost->id,
            'category' => $cost->category,
            'amount' => $cost->amount,
            'currency' => $cost->currency,
            'period_start' => $cost->period_start->format('Y-m-d'),
            'period_end' => $cost->period_end?->format('Y-m-d'),
            'period' => $cost->period,
            'description' => $cost->description,
            'fuel_liters' => $cost->fuel_liters === null ? null : (string) $cost->fuel_liters,
            'fuel_unit_price' => $cost->fuel_unit_price === null ? null : (string) $cost->fuel_unit_price,
            'fuel_odometer_value' => $cost->fuel_odometer_value === null ? null : (string) $cost->fuel_odometer_value,
        ];
    }

    private function subtractDistance(string $larger, string $smaller): string
    {
        $left = $this->scaledDistance($larger);
        $right = $this->scaledDistance($smaller);
        $result = '';
        $borrow = 0;

        for ($index = strlen($left) - 1; $index >= 0; $index--) {
            $digit = (int) $left[$index] - (int) $right[$index] - $borrow;
            $borrow = $digit < 0 ? 1 : 0;
            $result = (string) ($digit < 0 ? $digit + 10 : $digit).$result;
        }

        $whole = ltrim(substr($result, 0, -3), '0');

        return ($whole === '' ? '0' : $whole).'.'.substr($result, -3);
    }

    private function scaledDistance(string $value): string
    {
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return str_pad($whole, 17, '0', STR_PAD_LEFT).str_pad($fraction, 3, '0');
    }

    private function nullableDistance(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return VehicleDecimalValue::distance($value)
            ?? throw ValidationException::withMessages(['odometer_value' => 'Enter a non-negative distance with up to 17 whole and 3 fractional digits.']);
    }

    private function nullableTrim(?string $value): ?string
    {
        $trimmed = trim($value ?? '');

        return $trimmed === '' ? null : $trimmed;
    }
}
