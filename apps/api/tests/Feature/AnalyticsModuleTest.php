<?php

namespace Tests\Feature;

use App\Models\HouseholdSettings;
use App\Models\ModuleUserPermission;
use App\Models\ResourceUserPermission;
use App\Models\User;
use App\Models\UtilityMeter;
use App\Models\UtilityReading;
use App\Models\Vehicle;
use App\Models\VehicleOdometerReading;
use App\Services\Modules\ModuleManagementService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AnalyticsModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_utility_and_vehicle_values_are_grouped_by_unit_and_hidden_readings_break_intervals(): void
    {
        $admin = $this->member(true);
        $viewer = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $owner = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $this->enable($admin, ['analytics', 'utilities', 'vehicles']);
        $this->grant($viewer, 'analytics', ['view' => true, 'create' => true]);
        $this->grant($viewer, 'utilities', ['view' => true]);
        $this->grant($viewer, 'vehicles', ['view' => true]);

        $meter = UtilityMeter::query()->create([
            'owner_user_id' => null, 'created_by_user_id' => $admin->id, 'name' => 'Shared electricity',
            'meter_type' => 'electricity', 'unit' => 'kWh', 'is_private' => false,
        ]);
        $base = CarbonImmutable::today()->subDays(4);
        $this->utilityReading($meter, null, false, '100.000000', $base->format('Y-m-d'));
        $this->utilityReading($meter, $owner, true, '105.000000', $base->addDay()->format('Y-m-d'));
        $this->utilityReading($meter, null, false, '110.000000', $base->addDays(2)->format('Y-m-d'));
        $this->utilityReading($meter, null, false, '111.000000', $base->addDays(3)->format('Y-m-d'));

        $water = UtilityMeter::query()->create([
            'owner_user_id' => null, 'created_by_user_id' => $admin->id, 'name' => 'Shared water',
            'meter_type' => 'water', 'unit' => 'm³', 'is_private' => false,
        ]);
        $this->utilityReading($water, null, false, '10.000000', $base->addDays(2)->format('Y-m-d'));
        $this->utilityReading($water, null, false, '12.125000', $base->addDays(3)->format('Y-m-d'));

        $km = $this->vehicle($admin, false, 'km');
        $this->odometerReading($km, null, false, '100.000', $base->format('Y-m-d'));
        $this->odometerReading($km, null, false, '125.125', $base->addDays(3)->format('Y-m-d'));
        $mi = $this->vehicle($admin, false, 'mi');
        $this->odometerReading($mi, null, false, '10.000', $base->format('Y-m-d'));
        $this->odometerReading($mi, null, false, '14.000', $base->addDays(3)->format('Y-m-d'));
        $privateVehicle = $this->vehicle($owner, true, 'km');
        $this->odometerReading($privateVehicle, $owner, true, '50.000', $base->format('Y-m-d'));
        $this->odometerReading($privateVehicle, $owner, true, '900.000', $base->addDays(3)->format('Y-m-d'));

        $this->createWidget($viewer, 'utilities.consumption', '30d');
        $this->createWidget($viewer, 'vehicles.distance', '30d');
        $this->actingAs($viewer, 'web')->postJson('/api/analytics/widgets', [
            'metric_key' => 'utilities.consumption', 'range_key' => '30d', 'chart_type' => 'bar',
            'measure_key' => 'value', 'group_key' => 'record',
        ])->assertCreated()->assertJsonPath('widget.measure_key', 'value')->assertJsonPath('widget.group_key', 'record');
        $dashboard = $this->actingAs($viewer, 'web')->getJson('/api/analytics/dashboard')->assertOk();
        $utility = collect($dashboard->json('widgets'))->firstWhere('metric_key', 'utilities.consumption');
        $vehicle = collect($dashboard->json('widgets'))->firstWhere('metric_key', 'vehicles.distance');
        $readingsWidget = collect($dashboard->json('widgets'))->first(fn (array $widget): bool => $widget['measure_key'] === 'value');

        $this->assertSame('1.000000', collect($utility['series'])->firstWhere('label', 'kWh')['total']);
        $this->assertSame('2.125000', collect($utility['series'])->firstWhere('label', 'm³')['total']);
        $this->assertSame('25.125', collect($vehicle['series'])->firstWhere('label', 'km')['total']);
        $this->assertSame('4.000', collect($vehicle['series'])->firstWhere('label', 'mi')['total']);
        $this->assertSame('321.000000', collect($readingsWidget['series'])->firstWhere('label', 'Shared electricity')['total']);
        $this->assertStringNotContainsString('900.000', json_encode($dashboard->json(), JSON_THROW_ON_ERROR));
    }

    private function member(bool $admin): User
    {
        HouseholdSettings::query()->whereKey(1)->update(['setup_completed_at' => now()]);

        return User::factory()->create(['is_admin' => $admin, 'is_active' => true]);
    }

    /** @param list<string> $modules */
    private function enable(User $admin, array $modules): void
    {
        foreach ($modules as $module) {
            app(ModuleManagementService::class)->setEnabled($admin, $module, true);
        }
    }

    /** @param array{view?: bool, create?: bool, edit?: bool, manage?: bool} $capabilities */
    private function grant(User $member, string $module, array $capabilities): void
    {
        ModuleUserPermission::query()->updateOrCreate(
            ['user_id' => $member->id, 'module_key' => $module],
            [
                'can_view' => $capabilities['view'] ?? false,
                'can_create' => $capabilities['create'] ?? false,
                'can_edit' => $capabilities['edit'] ?? false,
                'can_manage' => $capabilities['manage'] ?? false,
            ],
        );
    }

    private function createWidget(User $owner, string $metric, string $range): void
    {
        $this->actingAs($owner, 'web')->postJson('/api/analytics/widgets', [
            'metric_key' => $metric,
            'range_key' => $range,
            'chart_type' => 'line',
        ])->assertCreated();
    }

    private function utilityReading(UtilityMeter $meter, ?User $owner, bool $private, string $value, string $date): void
    {
        UtilityReading::query()->create([
            'utility_meter_id' => $meter->id,
            'owner_user_id' => $private ? $owner?->id : null,
            'created_by_user_id' => $owner?->id ?? $meter->created_by_user_id,
            'is_private' => $private,
            'reading_date' => $date,
            'value' => $value,
        ]);
    }

    private function vehicle(User $owner, bool $private, string $unit): Vehicle
    {
        return Vehicle::query()->create([
            'owner_user_id' => $private ? $owner->id : null,
            'created_by_user_id' => $owner->id,
            'name' => fake()->unique()->word(),
            'make' => 'Example',
            'model' => 'Model',
            'fuel_type' => 'petrol',
            'odometer_unit' => $unit,
            'is_private' => $private,
        ]);
    }

    private function odometerReading(Vehicle $vehicle, ?User $owner, bool $private, string $value, string $date): void
    {
        VehicleOdometerReading::query()->create([
            'vehicle_id' => $vehicle->id,
            'owner_user_id' => $private ? $owner?->id : null,
            'created_by_user_id' => $owner?->id ?? $vehicle->created_by_user_id,
            'is_private' => $private,
            'reading_date' => $date,
            'value' => $value,
        ]);
    }
}
