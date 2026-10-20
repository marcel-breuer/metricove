<?php

namespace Tests\Feature;

use App\Models\FinanceCategory;
use App\Models\FinancialTransaction;
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

    public function test_finance_widgets_respect_record_visibility_and_keep_currencies_separate(): void
    {
        $admin = $this->member(true);
        $viewer = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $owner = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $this->enable($admin, ['analytics', 'finance']);
        $this->grant($viewer, 'analytics', ['view' => true, 'create' => true, 'edit' => true]);
        $this->grant($viewer, 'finance', ['view' => true]);

        $sharedCategory = $this->category($admin, false);
        $ownCategory = $this->category($viewer, true);
        $privateCategory = $this->category($owner, true);
        $date = CarbonImmutable::today()->subDay()->format('Y-m-d');
        $older = CarbonImmutable::today()->subDays(60)->format('Y-m-d');
        $this->transaction($sharedCategory, $admin, false, '12.50', 'EUR', $date);
        $this->transaction($ownCategory, $viewer, true, '2.50', 'EUR', $date);
        $granted = $this->transaction($privateCategory, $owner, true, '5.00', 'EUR', $date);
        ResourceUserPermission::query()->create([
            'resource_type' => $granted->getMorphClass(),
            'resource_id' => $granted->id,
            'user_id' => $viewer->id,
            'capability' => 'view',
        ]);
        $this->transaction($privateCategory, $owner, true, '900.00', 'EUR', $date, 'Hidden private expense');
        $this->transaction($sharedCategory, $admin, false, '3.25', 'USD', $date);
        $this->transaction($sharedCategory, $admin, false, '4.00', 'EUR', $older);

        $this->createWidget($viewer, 'finance.expenses', '30d');
        $this->createWidget($viewer, 'finance.expenses', '90d');

        $dashboard = $this->actingAs($viewer, 'web')->getJson('/api/analytics/dashboard')->assertOk();
        $widgets = $dashboard->json('widgets');
        $this->assertCount(2, $widgets);
        $this->assertTrue($widgets[0]['available']);
        $this->assertCount(2, $widgets[0]['series']);
        $eur = collect($widgets[0]['series'])->firstWhere('label', 'EUR');
        $usd = collect($widgets[0]['series'])->firstWhere('label', 'USD');
        $this->assertSame('20.00', $eur['total']);
        $this->assertSame('3.25', $usd['total']);
        $this->assertSame(30, count($widgets[0]['periods']));
        $this->assertSame('24.00', collect($widgets[1]['series'])->firstWhere('label', 'EUR')['total']);
        $this->assertStringNotContainsString('Hidden private expense', json_encode($dashboard->json(), JSON_THROW_ON_ERROR));

        $other = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $this->grant($other, 'analytics', ['view' => true, 'edit' => true]);
        $widgetId = (int) $widgets[0]['id'];
        $this->actingAs($other, 'web')->patchJson("/api/analytics/widgets/{$widgetId}", [
            'expected_version' => 1, 'title' => 'Stolen widget', 'range_key' => '30d', 'chart_type' => 'line',
        ])->assertNotFound();
        $this->assertDatabaseHas('analytics_widgets', ['id' => $widgetId, 'user_id' => $viewer->id]);
    }

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
        $dashboard = $this->actingAs($viewer, 'web')->getJson('/api/analytics/dashboard')->assertOk();
        $utility = collect($dashboard->json('widgets'))->firstWhere('metric_key', 'utilities.consumption');
        $vehicle = collect($dashboard->json('widgets'))->firstWhere('metric_key', 'vehicles.distance');

        $this->assertSame('1.000000', collect($utility['series'])->firstWhere('label', 'kWh')['total']);
        $this->assertSame('2.125000', collect($utility['series'])->firstWhere('label', 'm³')['total']);
        $this->assertSame('25.125', collect($vehicle['series'])->firstWhere('label', 'km')['total']);
        $this->assertSame('4.000', collect($vehicle['series'])->firstWhere('label', 'mi')['total']);
        $this->assertStringNotContainsString('900.000', json_encode($dashboard->json(), JSON_THROW_ON_ERROR));
    }

    public function test_disabled_or_unpermitted_sources_are_not_available_and_return_no_values(): void
    {
        $admin = $this->member(true);
        $viewer = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $owner = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $this->enable($admin, ['analytics', 'finance']);
        $this->grant($viewer, 'analytics', ['view' => true, 'create' => true]);
        $this->grant($viewer, 'finance', []);
        $this->grant($owner, 'finance', ['view' => true]);
        $category = $this->category($owner, true);
        $this->transaction($category, $owner, true, '500.00', 'EUR', CarbonImmutable::today()->format('Y-m-d'));

        $this->actingAs($viewer, 'web')->getJson('/api/analytics/catalog')->assertOk()->assertJsonCount(0, 'metrics');
        $this->actingAs($viewer, 'web')->postJson('/api/analytics/widgets', [
            'metric_key' => 'finance.expenses', 'range_key' => '30d', 'chart_type' => 'line',
        ])->assertForbidden();

        $this->grant($viewer, 'finance', ['view' => true]);
        $this->createWidget($viewer, 'finance.expenses', '30d');
        app(ModuleManagementService::class)->setEnabled($admin, 'finance', false);
        $dashboard = $this->actingAs($viewer, 'web')->getJson('/api/analytics/dashboard')->assertOk();
        $this->assertSame([], $dashboard->json('catalog'));
        $this->assertFalse($dashboard->json('widgets.0.available'));
        $this->assertSame([], $dashboard->json('widgets.0.series'));
        $this->assertDatabaseHas('financial_transactions', ['amount' => '500.00']);
    }

    public function test_repeated_widgets_share_one_source_aggregation_query(): void
    {
        $admin = $this->member(true);
        $this->enable($admin, ['analytics', 'finance']);
        $this->grant($admin, 'analytics', ['view' => true, 'create' => true]);
        $this->grant($admin, 'finance', ['view' => true]);
        $category = $this->category($admin, true);
        $this->transaction($category, $admin, true, '10.00', 'EUR', CarbonImmutable::today()->format('Y-m-d'));

        for ($index = 0; $index < 12; $index++) {
            $this->createWidget($admin, 'finance.expenses', $index % 2 === 0 ? '30d' : '12m');
        }

        $transactionQueries = 0;
        DB::listen(function (QueryExecuted $query) use (&$transactionQueries): void {
            if (str_contains(strtolower($query->sql), 'financial_transactions')) {
                $transactionQueries++;
            }
        });

        $dashboard = $this->actingAs($admin, 'web')->getJson('/api/analytics/dashboard')->assertOk();
        $this->assertCount(12, $dashboard->json('widgets'));
        $this->assertSame(1, $transactionQueries);
        $this->actingAs($admin, 'web')->postJson('/api/analytics/widgets', [
            'metric_key' => 'finance.expenses', 'range_key' => '30d', 'chart_type' => 'line',
        ])->assertUnprocessable()->assertJsonValidationErrors('widgets');
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

    private function category(User $owner, bool $private): FinanceCategory
    {
        return FinanceCategory::query()->create([
            'owner_user_id' => $private ? $owner->id : null,
            'created_by_user_id' => $owner->id,
            'is_private' => $private,
            'type' => 'expense',
            'name' => fake()->unique()->words(2, true),
        ]);
    }

    private function transaction(
        FinanceCategory $category,
        User $owner,
        bool $private,
        string $amount,
        string $currency,
        string $date,
        string $description = 'Ordinary expense',
    ): FinancialTransaction {
        return FinancialTransaction::query()->create([
            'finance_category_id' => $category->id,
            'owner_user_id' => $private ? $owner->id : null,
            'created_by_user_id' => $owner->id,
            'is_private' => $private,
            'type' => 'expense',
            'description' => $description,
            'amount' => $amount,
            'currency' => $currency,
            'occurred_on' => $date,
        ]);
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
