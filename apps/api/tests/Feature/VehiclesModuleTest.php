<?php

namespace Tests\Feature;

use App\Enums\ModuleCapability;
use App\Models\HouseholdSettings;
use App\Models\ModuleUserPermission;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleMaintenanceRecord;
use App\Models\VehicleOdometerReading;
use App\Services\Modules\ModuleAccessService;
use App\Services\Modules\ModuleManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class VehiclesModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_vehicle_odometer_is_unit_safe_chronological_and_exact(): void
    {
        $admin = $this->admin();
        $this->grant($admin, ['view' => true, 'manage' => true]);
        app(ModuleManagementService::class)->setEnabled($admin, 'vehicles', true);

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
        ])->assertCreated()->assertJsonPath('cost.amount', '123.45')->assertJsonPath('cost.currency', 'EUR');
        $this->assertDatabaseHas('vehicle_costs', [
            'vehicle_id' => $vehicleId, 'amount' => '123.45', 'currency' => 'EUR', 'period' => 'once',
        ]);

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
