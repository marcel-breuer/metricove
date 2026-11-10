<?php

namespace Tests\Feature;

use App\Enums\ModuleCapability;
use App\Models\HouseholdSettings;
use App\Models\ModuleUserPermission;
use App\Models\ResourceUserPermission;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCost;
use App\Models\VehicleMaintenanceRecord;
use App\Models\VehicleOdometerReading;
use App\Services\Modules\ModuleAccessService;
use App\Services\Modules\ModuleManagementService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class VehiclesModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_vehicle_odometer_is_unit_safe_chronological_and_exact(): void
    {
        $admin = $this->admin();
        app(ModuleManagementService::class)->setEnabled($admin, 'vehicles', true);
        $this->grant($admin, ['view' => true, 'manage' => true]);

        $vehicleId = (int) $this->actingAs($admin, 'web')->postJson('/api/vehicles', [
            'name' => 'Family car', 'make' => 'Volvo', 'model' => 'V60', 'model_year' => 2022,
            'fuel_type' => 'hybrid', 'odometer_unit' => 'km', 'visibility' => 'household',
        ])->assertCreated()->assertJsonPath('vehicle.odometer_unit', 'km')->json('vehicle.id');

        $this->actingAs($admin, 'web')->postJson("/api/vehicles/{$vehicleId}/odometer-readings", [
            'reading_date' => '2026-10-01', 'value' => '120000',
        ])->assertForbidden();
        $this->grant($admin, ['view' => true, 'create' => true, 'manage' => true]);

        $this->odometer($admin, $vehicleId, '2026-10-01', '120000');
        $later = $this->odometer($admin, $vehicleId, '2026-10-20', '120555.125');
        $this->assertSame('120555.125', $later->json('reading.value'));
        $middle = $this->odometer($admin, $vehicleId, '2026-10-10', '120250.5');
        $this->assertSame('120250.500', $middle->json('reading.value'));

        $history = $this->actingAs($admin, 'web')->getJson("/api/vehicles/{$vehicleId}")->assertOk();
        $this->assertSame(['2026-10-01', '2026-10-10', '2026-10-20'], array_column($history->json('odometer_readings'), 'reading_date'));
        $this->assertSame('250.500', $history->json('odometer_readings.1.distance_since_previous'));
        $this->assertSame('304.625', $history->json('odometer_readings.2.distance_since_previous'));
        $this->assertSame('km', $history->json('odometer_readings.2.unit'));

        $this->actingAs($admin, 'web')->postJson("/api/vehicles/{$vehicleId}/odometer-readings", [
            'reading_date' => '2026-10-10', 'value' => '120251',
        ])->assertUnprocessable()->assertJsonValidationErrors('reading_date');
        $this->actingAs($admin, 'web')->postJson("/api/vehicles/{$vehicleId}/odometer-readings", [
            'reading_date' => '2026-10-11', 'value' => '120100',
        ])->assertUnprocessable()->assertJsonValidationErrors('value');
        $this->actingAs($admin, 'web')->postJson("/api/vehicles/{$vehicleId}/odometer-readings", [
            'reading_date' => '2026-10-21', 'value' => '-1',
        ])->assertUnprocessable()->assertJsonValidationErrors('value');
        $this->actingAs($admin, 'web')->postJson("/api/vehicles/{$vehicleId}/odometer-readings", [
            'reading_date' => '2026-10-21', 'value' => 0.1,
        ])->assertUnprocessable()->assertJsonValidationErrors('value');

        $this->actingAs($admin, 'web')->patchJson("/api/vehicles/{$vehicleId}", ['odometer_unit' => 'mi'])
            ->assertUnprocessable()->assertJsonValidationErrors('odometer_unit');
        $this->actingAs($admin, 'web')->patchJson("/api/vehicles/{$vehicleId}", ['name' => 'Updated family car'])
            ->assertOk()->assertJsonPath('vehicle.name', 'Updated family car');
    }

    public function test_odometer_history_is_paginated_newest_first_and_returns_older_pages(): void
    {
        $admin = $this->admin();
        app(ModuleManagementService::class)->setEnabled($admin, 'vehicles', true);
        $this->grant($admin, ['view' => true, 'create' => true, 'manage' => true]);
        $vehicleId = $this->createVehicle($admin, 'private');

        for ($day = 1; $day <= 35; $day++) {
            $date = CarbonImmutable::parse('2026-01-01')->addDays($day - 1)->format('Y-m-d');
            $this->odometer($admin, $vehicleId, $date, (string) (50000 + $day * 100));
        }

        $initial = $this->actingAs($admin, 'web')->getJson("/api/vehicles/{$vehicleId}")
            ->assertOk()
            ->assertJsonCount(5, 'odometer_readings')
            ->assertJsonPath('has_more_odometer_readings', true);
        $this->assertSame('2026-01-31', $initial->json('odometer_readings.0.reading_date'));
        $this->assertSame('2026-02-04', $initial->json('odometer_readings.4.reading_date'));

        $oldestVisible = $initial->json('odometer_readings.0');
        $olderPage = $this->actingAs($admin, 'web')->getJson(sprintf(
            '/api/vehicles/%d/odometer-readings?limit=5&before_reading_date=%s&before_id=%d',
            $vehicleId,
            $oldestVisible['reading_date'],
            $oldestVisible['id'],
        ))->assertOk()->assertJsonCount(5, 'odometer_readings')->assertJsonPath('has_more_odometer_readings', true);

        $this->assertSame('2026-01-26', $olderPage->json('odometer_readings.0.reading_date'));
        $this->assertSame('2026-01-30', $olderPage->json('odometer_readings.4.reading_date'));
        $this->assertSame('100.000', $olderPage->json('odometer_readings.0.distance_since_previous'));
    }

    public function test_vehicle_detail_returns_a_visible_rolling_year_of_monthly_distance_and_average_projection(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00:00'));
        $admin = $this->admin();
        $viewer = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $owner = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        app(ModuleManagementService::class)->setEnabled($admin, 'vehicles', true);
        $this->grant($admin, ['view' => true, 'create' => true, 'manage' => true]);
        $this->grant($viewer, ['view' => true]);
        $vehicleId = $this->createVehicle($admin, 'household');
        $vehicle = Vehicle::query()->findOrFail($vehicleId);

        $this->trendReading($vehicle, null, false, '1000.000', '2025-11-15');
        $this->trendReading($vehicle, null, false, '1100.000', '2025-12-10');
        $hiddenReading = $this->trendReading($vehicle, $owner, true, '18888.888', '2026-01-10');
        $this->trendReading($vehicle, null, false, '19000.000', '2026-02-10');
        $grantedReading = $this->trendReading($vehicle, $owner, true, '19050.000', '2026-03-10');
        ResourceUserPermission::query()->create([
            'resource_type' => $grantedReading->getMorphClass(),
            'resource_id' => $grantedReading->id,
            'user_id' => $viewer->id,
            'capability' => 'view',
        ]);
        $this->trendReading($vehicle, null, false, '19100.000', '2026-04-10');
        $this->trendReading($vehicle, null, false, '19700.000', '2026-09-10');
        VehicleCost::query()->create([
            'vehicle_id' => $vehicleId,
            'owner_user_id' => $owner->id,
            'created_by_user_id' => $owner->id,
            'is_private' => true,
            'category' => 'fuel',
            'amount' => '9876.54',
            'currency' => 'EUR',
            'period_start' => '2026-09-10',
            'period' => 'once',
        ]);

        $response = $this->actingAs($viewer, 'web')->getJson("/api/vehicles/{$vehicleId}")->assertOk();
        $trend = $response->json('distance_trend');
        $this->assertSame('km', $trend['unit']);
        $this->assertCount(12, $trend['months']);
        $this->assertSame('2025-12', $trend['months'][0]['period']);
        $this->assertSame('2026-11', $trend['months'][11]['period']);
        $this->assertSame('100.000', $trend['months'][0]['distance']);
        $this->assertSame('0.000', $trend['months'][1]['distance']);
        $this->assertSame('0.000', $trend['months'][2]['distance']);
        $this->assertSame('50.000', $trend['months'][3]['distance']);
        $this->assertSame('50.000', $trend['months'][4]['distance']);
        $this->assertSame('600.000', $trend['months'][9]['distance']);
        $this->assertSame('66.667', $trend['average_monthly_distance']);
        $this->assertSame([
            ['period' => '2026-12', 'distance' => '66.667'],
            ['period' => '2027-01', 'distance' => '66.667'],
            ['period' => '2027-02', 'distance' => '66.667'],
        ], $trend['projection']);
        $this->assertTrue($trend['has_data']);
        $this->assertStringNotContainsString('18888.888', json_encode($response->json(), JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('9876.54', json_encode($response->json(), JSON_THROW_ON_ERROR));
        $this->assertContains($grantedReading->id, array_column($response->json('odometer_readings'), 'id'));
        $this->assertNotContains($hiddenReading->id, array_column($response->json('odometer_readings'), 'id'));
    }

    public function test_vehicle_record_histories_load_the_newest_five_then_older_records(): void
    {
        $admin = $this->admin();
        app(ModuleManagementService::class)->setEnabled($admin, 'vehicles', true);
        $this->grant($admin, ['view' => true, 'create' => true, 'manage' => true]);
        $vehicleId = $this->createVehicle($admin, 'private');

        for ($day = 1; $day <= 7; $day++) {
            $date = CarbonImmutable::parse('2026-03-01')->addDays($day - 1)->format('Y-m-d');
            $this->actingAs($admin, 'web')->postJson("/api/vehicles/{$vehicleId}/maintenance", [
                'title' => "Service {$day}", 'service_type' => 'service', 'occurred_on' => $date,
            ])->assertCreated();
            $this->actingAs($admin, 'web')->postJson("/api/vehicles/{$vehicleId}/insurance", [
                'provider' => "Provider {$day}", 'coverage_type' => 'liability', 'valid_from' => $date,
                'valid_until' => CarbonImmutable::parse($date)->addYear()->format('Y-m-d'),
            ])->assertCreated();
            $this->actingAs($admin, 'web')->postJson("/api/vehicles/{$vehicleId}/costs", [
                'category' => 'fuel', 'amount' => '10.00', 'currency' => 'EUR', 'period_start' => $date, 'period' => 'once',
            ])->assertCreated();
        }

        $detail = $this->actingAs($admin, 'web')->getJson("/api/vehicles/{$vehicleId}")->assertOk();
        foreach (['maintenance_records', 'insurance_policies', 'costs'] as $history) {
            $this->assertCount(5, $detail->json($history));
            $this->assertTrue($detail->json("has_more_{$history}"));
        }
        $this->assertSame('Service 7', $detail->json('maintenance_records.0.title'));

        foreach ([
            ['maintenance', 'maintenance_records', 'Service 2'],
            ['insurance', 'insurance_policies', 'Provider 2'],
            ['costs', 'costs', 'fuel'],
        ] as [$type, $key, $expected]) {
            $older = $this->actingAs($admin, 'web')->getJson(sprintf(
                '/api/vehicles/%d/history/%s?limit=5&before_date=2026-03-03&before_id=%d',
                $vehicleId,
                $type,
                $detail->json("{$key}.4.id"),
            ))->assertOk()->assertJsonCount(2, $key)->assertJsonPath("has_more_{$key}", false);
            $actual = match ($type) {
                'maintenance' => $older->json("{$key}.0.title"),
                'insurance' => $older->json("{$key}.0.provider"),
                default => $older->json("{$key}.0.category"),
            };
            $this->assertSame($expected, $actual);
        }
    }

    public function test_odometer_corrections_keep_audit_history_and_check_neighbor_values(): void
    {
        $admin = $this->admin();
        $this->grant($admin, ['view' => true, 'create' => true, 'edit' => true, 'manage' => true]);
        app(ModuleManagementService::class)->setEnabled($admin, 'vehicles', true);
        $vehicleId = $this->createVehicle($admin, 'private');
        $this->odometer($admin, $vehicleId, '2026-10-01', '30000');
        $middle = $this->odometer($admin, $vehicleId, '2026-10-10', '30100');
        $readingId = (int) $middle->json('reading.id');
        $this->odometer($admin, $vehicleId, '2026-10-20', '30200');

        $this->actingAs($admin, 'web')->patchJson("/api/vehicles/odometer-readings/{$readingId}", [
            'reading_date' => '2026-10-10', 'value' => '29999.999', 'note' => null, 'reason' => 'Below previous reading',
        ])->assertUnprocessable()->assertJsonValidationErrors('value');
        $this->actingAs($admin, 'web')->patchJson("/api/vehicles/odometer-readings/{$readingId}", [
            'reading_date' => '2026-10-20', 'value' => '30150', 'note' => null, 'reason' => 'Duplicate another date',
        ])->assertUnprocessable()->assertJsonValidationErrors('reading_date');
        $this->actingAs($admin, 'web')->patchJson("/api/vehicles/odometer-readings/{$readingId}", [
            'reading_date' => '2026-10-15', 'value' => '30201', 'note' => null, 'reason' => 'Above next reading',
        ])->assertUnprocessable()->assertJsonValidationErrors('value');

        $this->actingAs($admin, 'web')->patchJson("/api/vehicles/odometer-readings/{$readingId}", [
            'reading_date' => '2026-10-15', 'value' => '30150.25', 'note' => 'Verified against receipt',
            'reason' => 'Corrected after checking the service receipt',
        ])->assertOk()->assertJsonPath('reading.value', '30150.250');

        $revisions = $this->actingAs($admin, 'web')->getJson("/api/vehicles/odometer-readings/{$readingId}/revisions")
            ->assertOk()->assertJsonPath('revisions.0.old_value', '30100.000')
            ->assertJsonPath('revisions.0.new_value', '30150.250')
            ->assertJsonPath('revisions.0.reason', 'Corrected after checking the service receipt');
        $this->assertCount(1, $revisions->json('revisions'));
        $this->assertDatabaseCount('vehicle_odometer_revisions', 1);
    }

    public function test_maintenance_insurance_and_decimal_costs_share_the_vehicle_policy(): void
    {
        $admin = $this->admin();
        $this->grant($admin, ['view' => true, 'create' => true, 'manage' => true]);
        app(ModuleManagementService::class)->setEnabled($admin, 'vehicles', true);
        $vehicleId = $this->createVehicle($admin, 'household');
        $this->odometer($admin, $vehicleId, '2026-10-01', '12000');

        $this->actingAs($admin, 'web')->postJson("/api/vehicles/{$vehicleId}/maintenance", [
            'title' => 'Annual inspection', 'service_type' => 'inspection', 'occurred_on' => '2026-10-03',
            'odometer_value' => '12005.125', 'note' => 'Passed',
        ])->assertCreated()->assertJsonPath('maintenance_record.odometer_unit', 'km');
        $this->actingAs($admin, 'web')->postJson("/api/vehicles/{$vehicleId}/odometer-readings", [
            'reading_date' => '2026-10-04', 'value' => '12004',
        ])->assertUnprocessable()->assertJsonValidationErrors('value');
        $this->odometer($admin, $vehicleId, '2026-10-04', '12006');

        $insuranceStart = now()->toDateString();
        $insuranceEnd = now()->addDays(30)->toDateString();
        $this->actingAs($admin, 'web')->postJson("/api/vehicles/{$vehicleId}/insurance", [
            'provider' => 'Example Mutual', 'coverage_type' => 'comprehensive',
            'valid_from' => $insuranceStart, 'valid_until' => $insuranceEnd, 'note' => null,
        ])->assertCreated()->assertJsonPath('insurance_policy.status', 'active');
        $this->actingAs($admin, 'web')->postJson("/api/vehicles/{$vehicleId}/insurance", [
            'provider' => 'Example Mutual', 'coverage_type' => 'liability',
            'valid_from' => $insuranceEnd, 'valid_until' => $insuranceStart,
        ])->assertUnprocessable()->assertJsonValidationErrors('valid_until');

        $this->actingAs($admin, 'web')->postJson("/api/vehicles/{$vehicleId}/costs", [
            'category' => 'fuel', 'amount' => '123.45', 'currency' => 'eur',
            'period_start' => '2026-10-02', 'period_end' => null, 'period' => 'once', 'description' => 'Fuel fill-up',
            'fuel_liters' => '40', 'fuel_unit_price' => '1.6', 'fuel_odometer_value' => '12006.2',
        ])->assertCreated()->assertJsonPath('cost.amount', '123.45')->assertJsonPath('cost.currency', 'EUR')
            ->assertJsonPath('cost.fuel_liters', '40.000')
            ->assertJsonPath('cost.fuel_unit_price', '1.6000')
            ->assertJsonPath('cost.fuel_odometer_value', '12006.200');
        $this->assertDatabaseHas('vehicle_costs', [
            'vehicle_id' => $vehicleId, 'amount' => '123.45', 'currency' => 'EUR', 'period' => 'once',
            'fuel_liters' => '40.000', 'fuel_unit_price' => '1.6000', 'fuel_odometer_value' => '12006.200',
        ]);
        $this->actingAs($admin, 'web')->postJson("/api/vehicles/{$vehicleId}/costs", [
            'category' => 'fuel', 'amount' => '123.45', 'currency' => 'EUR',
            'period_start' => '2026-10-02', 'period' => 'once', 'fuel_liters' => '40',
        ])->assertCreated()->assertJsonPath('cost.fuel_unit_price', '3.0863');

        $this->actingAs($admin, 'web')->postJson("/api/vehicles/{$vehicleId}/costs", [
            'category' => 'insurance', 'amount' => '12.345', 'currency' => 'EUR',
            'period_start' => '2026-10-01', 'period' => 'monthly',
        ])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->actingAs($admin, 'web')->postJson("/api/vehicles/{$vehicleId}/costs", [
            'category' => 'insurance', 'amount' => '12.30', 'currency' => 'EURO',
            'period_start' => '2026-10-01', 'period' => 'monthly',
        ])->assertUnprocessable()->assertJsonValidationErrors('currency');

        $this->actingAs($admin, 'web')->postJson("/api/vehicles/{$vehicleId}/costs", [
            'category' => 'insurance', 'amount' => '12.30', 'currency' => 'EUR',
            'period_start' => '2026-10-01', 'period' => 'monthly', 'description' => 'Monthly insurance premium',
        ])->assertCreated()->assertJsonPath('cost.period', 'monthly');

        $detail = $this->actingAs($admin, 'web')->getJson("/api/vehicles/{$vehicleId}")->assertOk();
        $this->assertCount(1, $detail->json('maintenance_records'));
        $this->assertCount(1, $detail->json('insurance_policies'));
        $monthlyCost = collect($detail->json('costs'))->firstWhere('description', 'Monthly insurance premium');
        $this->assertSame('monthly', $monthlyCost['period']);
    }

    public function test_vehicle_costs_can_be_updated_and_deleted_only_with_edit_access(): void
    {
        $admin = $this->admin();
        $this->grant($admin, ['view' => true, 'create' => true, 'edit' => true, 'manage' => true]);
        app(ModuleManagementService::class)->setEnabled($admin, 'vehicles', true);
        $vehicleId = $this->createVehicle($admin, 'household');
        $costId = (int) $this->actingAs($admin, 'web')->postJson("/api/vehicles/{$vehicleId}/costs", [
            'category' => 'fuel', 'amount' => '80.00', 'currency' => 'EUR',
            'period_start' => '2026-10-02', 'period' => 'once', 'description' => 'Old fill-up',
            'fuel_liters' => '40', 'fuel_unit_price' => '2', 'fuel_odometer_value' => '12000', 'fuel_full_tank' => true,
        ])->assertCreated()->json('cost.id');

        $this->actingAs($admin, 'web')->patchJson("/api/vehicles/costs/{$costId}", [
            'category' => 'fuel', 'amount' => '75.60', 'currency' => 'eur',
            'period_start' => '2026-10-03', 'period_end' => null, 'period' => 'once', 'description' => 'Corrected fill-up',
            'fuel_liters' => '40', 'fuel_unit_price' => '1.89', 'fuel_odometer_value' => '12020.5', 'fuel_full_tank' => true,
        ])->assertOk()->assertJsonPath('cost.amount', '75.60')
            ->assertJsonPath('cost.currency', 'EUR')
            ->assertJsonPath('cost.period_start', '2026-10-03')
            ->assertJsonPath('cost.description', 'Corrected fill-up')
            ->assertJsonPath('cost.fuel_liters', '40.000')
            ->assertJsonPath('cost.fuel_unit_price', '1.8900')
            ->assertJsonPath('cost.fuel_odometer_value', '12020.500')
            ->assertJsonPath('cost.fuel_full_tank', true)
            ->assertJsonPath('cost.fuel_consumption_unavailable_reason', 'missing_previous_full_tank');

        $this->assertDatabaseHas('vehicle_costs', [
            'id' => $costId, 'vehicle_id' => $vehicleId, 'amount' => '75.60', 'currency' => 'EUR',
            'description' => 'Corrected fill-up', 'fuel_unit_price' => '1.8900', 'fuel_odometer_value' => '12020.500',
            'fuel_full_tank' => true,
        ]);

        $this->actingAs($admin, 'web')->patchJson("/api/vehicles/costs/{$costId}", [
            'category' => 'fuel', 'amount' => '75.601', 'currency' => 'EUR',
            'period_start' => '2026-10-03', 'period' => 'once',
        ])->assertUnprocessable()->assertJsonValidationErrors('amount');

        $viewer = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $this->grant($viewer, ['view' => true]);
        $this->actingAs($viewer, 'web')->patchJson("/api/vehicles/costs/{$costId}", [
            'category' => 'other', 'amount' => '1.00', 'currency' => 'EUR',
            'period_start' => '2026-10-03', 'period' => 'once',
        ])->assertForbidden();

        $this->actingAs($admin, 'web')->deleteJson("/api/vehicles/costs/{$costId}")
            ->assertOk()->assertJsonPath('message', 'Vehicle cost deleted.');
        $this->assertDatabaseMissing('vehicle_costs', ['id' => $costId]);
    }

    public function test_vehicle_cost_invoices_are_private_and_follow_vehicle_access(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $owner = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $viewer = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $this->grant($admin, ['view' => true, 'create' => true, 'edit' => true, 'manage' => true]);
        $this->grant($owner, ['view' => true, 'create' => true, 'edit' => true]);
        $this->grant($viewer, ['view' => true]);
        app(ModuleManagementService::class)->setEnabled($admin, 'vehicles', true);
        $vehicleId = $this->actingAs($owner, 'web')->postJson('/api/vehicles', [
            'name' => 'Private car', 'make' => 'Volvo', 'model' => 'V60', 'model_year' => 2022,
            'fuel_type' => 'diesel', 'odometer_unit' => 'km', 'visibility' => 'private',
        ])->assertCreated()->json('vehicle.id');
        $pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF";
        $created = $this->actingAs($owner, 'web')->post("/api/vehicles/{$vehicleId}/costs", [
            'category' => 'maintenance', 'amount' => '125.00', 'currency' => 'EUR',
            'period_start' => '2026-11-10', 'period' => 'once', 'invoice' => UploadedFile::fake()->createWithContent('service.pdf', $pdf),
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('cost.invoice_name', 'service.pdf')
            ->assertJsonPath('cost.invoice_mime', 'application/pdf');
        $costId = (int) $created->json('cost.id');
        $cost = VehicleCost::query()->findOrFail($costId);
        $path = $cost->invoice_path;
        $this->assertNotNull($path);
        Storage::disk('local')->assertExists($path);
        $this->actingAs($owner, 'web')->get("/api/vehicles/costs/{$costId}/invoice")
            ->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs($viewer, 'web')->get("/api/vehicles/costs/{$costId}/invoice")->assertNotFound();

        $firstPath = $path;
        $this->actingAs($owner, 'web')->post("/api/vehicles/costs/{$costId}", [
            'category' => 'maintenance', 'amount' => '125.00', 'currency' => 'EUR',
            'period_start' => '2026-11-10', 'period' => 'once', 'invoice' => UploadedFile::fake()->createWithContent('service-replaced.pdf', $pdf),
        ], ['Accept' => 'application/json'])->assertOk();
        $path = VehicleCost::query()->findOrFail($costId)->invoice_path;
        $this->assertNotNull($path);
        Storage::disk('local')->assertMissing($firstPath);
        Storage::disk('local')->assertExists($path);

        $this->actingAs($owner, 'web')->post("/api/vehicles/costs/{$costId}", [
            'category' => 'maintenance', 'amount' => '125.00', 'currency' => 'EUR',
            'period_start' => '2026-11-10', 'period' => 'once', 'remove_invoice' => '1',
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('cost.invoice_url', null);
        Storage::disk('local')->assertMissing($path);

        $this->actingAs($owner, 'web')->post("/api/vehicles/costs/{$costId}", [
            'category' => 'maintenance', 'amount' => '125.00', 'currency' => 'EUR',
            'period_start' => '2026-11-10', 'period' => 'once', 'invoice' => UploadedFile::fake()->createWithContent('service-copy.pdf', $pdf),
        ], ['Accept' => 'application/json'])->assertOk();
        $replacementPath = VehicleCost::query()->findOrFail($costId)->invoice_path;
        $this->assertNotNull($replacementPath);
        Storage::disk('local')->assertExists($replacementPath);
        $this->actingAs($owner, 'web')->deleteJson("/api/vehicles/costs/{$costId}")->assertOk();
        Storage::disk('local')->assertMissing($replacementPath);
    }

    public function test_fuel_cost_fields_are_calculated_exactly_and_consumption_requires_consecutive_full_tanks(): void
    {
        $admin = $this->admin();
        app(ModuleManagementService::class)->setEnabled($admin, 'vehicles', true);
        $member = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $this->grant($member, ['view' => true, 'create' => true]);
        $vehicleId = $this->createVehicle($member, 'private');
        $this->grant($member, ['view' => true]);

        $this->actingAs($member, 'web')->postJson("/api/vehicles/{$vehicleId}/costs", [
            'category' => 'fuel', 'amount' => '60', 'fuel_liters' => '30', 'currency' => 'EUR',
            'period_start' => '2026-09-30', 'period' => 'once',
        ])->assertForbidden();
        $this->assertDatabaseCount('vehicle_costs', 0);
        $this->grant($member, ['view' => true, 'create' => true]);

        $first = $this->actingAs($member, 'web')->postJson("/api/vehicles/{$vehicleId}/costs", [
            'category' => 'fuel', 'fuel_liters' => '30', 'fuel_unit_price' => '2', 'currency' => 'EUR',
            'period_start' => '2026-10-01', 'period' => 'once', 'fuel_odometer_value' => '10000', 'fuel_full_tank' => true,
        ])->assertCreated()->assertJsonPath('cost.amount', '60.00')
            ->assertJsonPath('cost.fuel_consumption_l_per_100km', null)
            ->assertJsonPath('cost.fuel_consumption_unavailable_reason', 'missing_previous_full_tank');
        $this->assertSame('60.00', $first->json('cost.amount'));

        $this->actingAs($member, 'web')->postJson("/api/vehicles/{$vehicleId}/costs", [
            'category' => 'fuel', 'amount' => '80.00', 'fuel_liters' => '40', 'currency' => 'EUR',
            'period_start' => '2026-10-10', 'period' => 'once', 'fuel_odometer_value' => '10500', 'fuel_full_tank' => true,
        ])->assertCreated()->assertJsonPath('cost.fuel_unit_price', '2.0000')
            ->assertJsonPath('cost.fuel_consumption_unavailable_reason', null)
            ->assertJsonPath('cost.fuel_consumption_l_per_100km', '8.000');

        $this->actingAs($member, 'web')->postJson("/api/vehicles/{$vehicleId}/costs", [
            'category' => 'fuel', 'amount' => '20.00', 'fuel_liters' => '10', 'fuel_unit_price' => '2', 'currency' => 'EUR',
            'period_start' => '2026-10-12', 'period' => 'once', 'fuel_odometer_value' => '10500', 'fuel_full_tank' => true,
        ])->assertCreated()->assertJsonPath('cost.fuel_consumption_l_per_100km', null)
            ->assertJsonPath('cost.fuel_consumption_unavailable_reason', 'non_comparable');

        $this->actingAs($member, 'web')->postJson("/api/vehicles/{$vehicleId}/costs", [
            'category' => 'fuel', 'amount' => '20.00', 'fuel_unit_price' => '2', 'currency' => 'EUR',
            'period_start' => '2026-10-15', 'period' => 'once', 'fuel_odometer_value' => '10600', 'fuel_full_tank' => false,
        ])->assertCreated()->assertJsonPath('cost.fuel_liters', '10.000')
            ->assertJsonPath('cost.fuel_consumption_unavailable_reason', 'not_full_to_full');

        $this->actingAs($member, 'web')->postJson("/api/vehicles/{$vehicleId}/costs", [
            'category' => 'fuel', 'amount' => '20.00', 'fuel_liters' => '10', 'fuel_unit_price' => '9', 'currency' => 'EUR',
            'period_start' => '2026-10-20', 'period' => 'once', 'fuel_odometer_value' => '10700', 'fuel_full_tank' => true,
        ])->assertCreated()->assertJsonPath('cost.amount', '20.00')->assertJsonPath('cost.fuel_unit_price', '9.0000')
            ->assertJsonPath('cost.fuel_consumption_unavailable_reason', 'missing_previous_full_tank');

        $this->actingAs($member, 'web')->postJson("/api/vehicles/{$vehicleId}/costs", [
            'category' => 'fuel', 'amount' => 'invalid', 'fuel_liters' => '10', 'fuel_unit_price' => '2', 'currency' => 'EUR',
            'period_start' => '2026-10-21', 'period' => 'once',
        ])->assertUnprocessable()->assertJsonValidationErrors('amount');

        $this->actingAs($member, 'web')->postJson("/api/vehicles/{$vehicleId}/costs", [
            'category' => 'insurance', 'amount' => '12.30', 'currency' => 'EUR',
            'period_start' => '2026-10-01', 'period' => 'monthly', 'fuel_full_tank' => false,
        ])->assertCreated();
    }

    public function test_private_vehicle_and_related_records_require_exact_grants_and_disable_preserves_data(): void
    {
        $admin = $this->admin();
        $owner = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $viewer = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $this->grant($admin, ['view' => true, 'manage' => true]);
        $this->grant($owner, ['view' => true, 'create' => true, 'edit' => true, 'manage' => true]);
        $this->grant($viewer, ['view' => true, 'edit' => true]);
        app(ModuleManagementService::class)->setEnabled($admin, 'vehicles', true);
        $vehicleId = $this->createVehicle($owner, 'private');
        $readingId = (int) $this->odometer($owner, $vehicleId, '2026-10-01', '50000')->json('reading.id');
        $maintenanceId = (int) $this->actingAs($owner, 'web')->postJson("/api/vehicles/{$vehicleId}/maintenance", [
            'title' => 'Tire change', 'service_type' => 'tires', 'occurred_on' => '2026-10-02',
        ])->assertCreated()->json('maintenance_record.id');
        $this->actingAs($owner, 'web')->postJson("/api/vehicles/{$vehicleId}/costs", [
            'category' => 'maintenance', 'amount' => '80.00', 'currency' => 'EUR',
            'period_start' => '2026-10-02', 'period' => 'once',
        ])->assertCreated();

        $this->actingAs($admin, 'web')->getJson("/api/vehicles/{$vehicleId}")->assertNotFound();
        $this->actingAs($viewer, 'web')->getJson("/api/vehicles/{$vehicleId}")->assertNotFound();
        $this->actingAs($admin, 'web')->getJson('/api/vehicles')->assertOk()->assertJsonCount(0, 'vehicles');
        $this->actingAs($viewer, 'web')->getJson('/api/vehicles')->assertOk()->assertJsonCount(0, 'vehicles');
        $access = app(ModuleAccessService::class);
        $access->grantPrivateResourceAccess($owner, Vehicle::query()->findOrFail($vehicleId), $viewer, ModuleCapability::View);
        $access->grantPrivateResourceAccess($owner, VehicleOdometerReading::query()->findOrFail($readingId), $viewer, ModuleCapability::View);
        $access->grantPrivateResourceAccess($owner, VehicleMaintenanceRecord::query()->findOrFail($maintenanceId), $viewer, ModuleCapability::View);

        $this->actingAs($viewer, 'web')->getJson("/api/vehicles/{$vehicleId}")
            ->assertOk()->assertJsonCount(1, 'odometer_readings')->assertJsonCount(1, 'maintenance_records')->assertJsonCount(0, 'costs');
        $this->actingAs($viewer, 'web')->getJson("/api/vehicles/{$vehicleId}/history/maintenance")
            ->assertOk()->assertJsonCount(1, 'maintenance_records');
        $this->actingAs($viewer, 'web')->getJson("/api/vehicles/{$vehicleId}/history/costs")
            ->assertOk()->assertJsonCount(0, 'costs');
        $this->actingAs($viewer, 'web')->getJson('/api/vehicles')->assertOk()->assertJsonCount(1, 'vehicles');
        $this->grant($viewer, ['view' => false, 'edit' => true]);
        $this->actingAs($viewer, 'web')->getJson("/api/vehicles/{$vehicleId}")->assertForbidden();

        app(ModuleManagementService::class)->setEnabled($admin, 'vehicles', false);
        $this->actingAs($owner, 'web')->getJson('/api/vehicles')->assertNotFound();
        $this->actingAs($owner, 'web')->postJson("/api/vehicles/{$vehicleId}/costs", [
            'category' => 'fuel', 'amount' => '1.00', 'currency' => 'EUR', 'period_start' => '2026-10-03', 'period' => 'once',
        ])->assertNotFound();
        app(ModuleManagementService::class)->setEnabled($admin, 'vehicles', true);
        $this->actingAs($owner, 'web')->getJson("/api/vehicles/{$vehicleId}")->assertOk();
        $this->assertDatabaseCount('vehicles', 1);
        $this->assertDatabaseCount('vehicle_odometer_readings', 1);
        $this->assertDatabaseCount('vehicle_costs', 1);
    }

    private function createVehicle(User $member, string $visibility): int
    {
        return (int) $this->actingAs($member, 'web')->postJson('/api/vehicles', [
            'name' => 'Daily car', 'make' => 'Volvo', 'model' => 'V60', 'model_year' => 2022,
            'fuel_type' => 'diesel', 'odometer_unit' => 'km', 'visibility' => $visibility,
        ])->assertCreated()->json('vehicle.id');
    }

    private function odometer(User $member, int $vehicleId, string $date, string $value): TestResponse
    {
        return $this->actingAs($member, 'web')->postJson("/api/vehicles/{$vehicleId}/odometer-readings", [
            'reading_date' => $date, 'value' => $value,
        ])->assertCreated();
    }

    private function trendReading(Vehicle $vehicle, ?User $owner, bool $private, string $value, string $date): VehicleOdometerReading
    {
        return VehicleOdometerReading::query()->create([
            'vehicle_id' => $vehicle->id,
            'owner_user_id' => $private ? $owner?->id : null,
            'created_by_user_id' => $owner?->id ?? $vehicle->created_by_user_id,
            'is_private' => $private,
            'reading_date' => $date,
            'value' => $value,
        ]);
    }

    private function admin(): User
    {
        HouseholdSettings::query()->whereKey(1)->update(['setup_completed_at' => now()]);

        return User::factory()->create([
            'name' => 'Vehicles Admin', 'email' => 'vehicles-admin@example.test',
            'is_admin' => true, 'is_active' => true,
        ]);
    }

    /** @param array{view?: bool, create?: bool, edit?: bool, manage?: bool} $capabilities */
    private function grant(User $member, array $capabilities): void
    {
        ModuleUserPermission::query()->updateOrCreate(
            ['user_id' => $member->id, 'module_key' => 'vehicles'],
            [
                'can_view' => $capabilities['view'] ?? false,
                'can_create' => $capabilities['create'] ?? false,
                'can_edit' => $capabilities['edit'] ?? false,
                'can_manage' => $capabilities['manage'] ?? false,
            ],
        );
    }
}
