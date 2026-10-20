<?php

namespace Tests\Feature;

use App\Enums\ModuleCapability;
use App\Models\HouseholdSettings;
use App\Models\ModuleUserPermission;
use App\Models\User;
use App\Models\UtilityMeter;
use App\Models\UtilityReading;
use App\Services\Modules\ModuleAccessService;
use App\Services\Modules\ModuleManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class UtilitiesModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_meter_units_chronological_usage_and_alerts_use_exact_decimals(): void
    {
        $admin = $this->admin();
        app(ModuleManagementService::class)->setEnabled($admin, 'utilities', true);
        $this->grant($admin, ['view' => true, 'create' => false, 'edit' => true, 'manage' => true]);

        $created = $this->actingAs($admin, 'web')->postJson('/api/utilities/meters', [
            'name' => 'Main electricity',
            'meter_type' => 'electricity',
            'visibility' => 'household',
            'description' => 'Whole-house meter',
            'alert_threshold' => '25.000001',
        ])->assertCreated()
            ->assertJsonPath('meter.unit', 'kWh')
            ->assertJsonPath('meter.visibility', 'household');
        $meterId = (int) $created->json('meter.id');

        $this->actingAs($admin, 'web')->postJson("/api/utilities/meters/{$meterId}/readings", [
            'reading_date' => '2026-10-01', 'value' => '100',
        ])->assertForbidden();
        $this->grant($admin, ['view' => true, 'create' => true, 'edit' => true, 'manage' => true]);

        $this->actingAs($admin, 'web')->postJson("/api/utilities/meters/{$meterId}/readings", [
            'reading_date' => '2026-10-01', 'value' => '100',
        ])->assertCreated()
            ->assertJsonPath('reading.consumption_since_previous', null);

        $later = $this->actingAs($admin, 'web')->postJson("/api/utilities/meters/{$meterId}/readings", [
            'reading_date' => '2026-10-10', 'value' => '150.000001',
        ])->assertCreated();
        $this->assertSame('50.000001', $later->json('reading.consumption_since_previous'));
        $this->assertTrue($later->json('reading.alert'));

        $middle = $this->actingAs($admin, 'web')->postJson("/api/utilities/meters/{$meterId}/readings", [
            'reading_date' => '2026-10-05', 'value' => '125',
        ])->assertCreated();
        $this->assertSame('25.000000', $middle->json('reading.consumption_since_previous'));
        $this->assertFalse($middle->json('reading.alert'));

        $history = $this->actingAs($admin, 'web')->getJson("/api/utilities/meters/{$meterId}")
            ->assertOk()
            ->assertJsonPath('meter.unit', 'kWh');
        $this->assertSame(['2026-10-01', '2026-10-05', '2026-10-10'], array_column($history->json('readings'), 'reading_date'));
        $this->assertSame('25.000001', $history->json('readings.2.consumption_since_previous'));
        $this->assertFalse($history->json('readings.2.alert'));

        $this->actingAs($admin, 'web')->postJson("/api/utilities/meters/{$meterId}/readings", [
            'reading_date' => '2026-10-05', 'value' => '124.9',
        ])->assertUnprocessable()->assertJsonValidationErrors('reading_date');
        $this->actingAs($admin, 'web')->postJson("/api/utilities/meters/{$meterId}/readings", [
            'reading_date' => '2026-10-11', 'value' => '-1',
        ])->assertUnprocessable()->assertJsonValidationErrors('value');
        $this->actingAs($admin, 'web')->postJson("/api/utilities/meters/{$meterId}/readings", [
            'reading_date' => '2026-10-11', 'value' => 0.1,
        ])->assertUnprocessable()->assertJsonValidationErrors('value');
        $this->actingAs($admin, 'web')->postJson('/api/utilities/meters', [
            'name' => 'Invalid meter', 'meter_type' => 'steam', 'visibility' => 'household',
        ])->assertUnprocessable()->assertJsonValidationErrors('meter_type');
    }

    public function test_corrections_preserve_audit_history_and_validate_both_neighbors(): void
    {
        $admin = $this->admin();
        $this->grant($admin, ['view' => true, 'create' => true, 'edit' => true, 'manage' => true]);
        app(ModuleManagementService::class)->setEnabled($admin, 'utilities', true);
        $meterId = (int) $this->actingAs($admin, 'web')->postJson('/api/utilities/meters', [
            'name' => 'Water meter', 'meter_type' => 'water', 'visibility' => 'private',
        ])->assertCreated()->json('meter.id');

        $this->read($admin, $meterId, '2026-10-01', '100');
        $middle = $this->read($admin, $meterId, '2026-10-10', '150');
        $middleId = (int) $middle->json('reading.id');
        $this->read($admin, $meterId, '2026-10-20', '200');

        $this->actingAs($admin, 'web')->patchJson("/api/utilities/readings/{$middleId}", [
            'reading_date' => '2026-10-10', 'value' => '99.999999', 'note' => null, 'reason' => 'Correct transcription error',
        ])->assertUnprocessable()->assertJsonValidationErrors('value');
        $this->actingAs($admin, 'web')->patchJson("/api/utilities/readings/{$middleId}", [
            'reading_date' => '2026-10-20', 'value' => '175', 'note' => null, 'reason' => 'Move to the duplicate date',
        ])->assertUnprocessable()->assertJsonValidationErrors('reading_date');
        $this->actingAs($admin, 'web')->patchJson("/api/utilities/readings/{$middleId}", [
            'reading_date' => '2026-10-15', 'value' => '201', 'note' => null, 'reason' => 'Above next meter value',
        ])->assertUnprocessable()->assertJsonValidationErrors('value');

        $corrected = $this->actingAs($admin, 'web')->patchJson("/api/utilities/readings/{$middleId}", [
            'reading_date' => '2026-10-15', 'value' => '175.25', 'note' => 'Photo confirmed', 'reason' => 'Corrected from the meter photo',
        ])->assertOk()
            ->assertJsonPath('reading.reading_date', '2026-10-15')
            ->assertJsonPath('reading.value', '175.250000');
        $this->assertSame(1, $corrected->json('reading.revision_count'));

        $revisions = $this->actingAs($admin, 'web')->getJson("/api/utilities/readings/{$middleId}/revisions")
            ->assertOk()
            ->assertJsonPath('revisions.0.old_value', '150.000000')
            ->assertJsonPath('revisions.0.new_value', '175.250000')
            ->assertJsonPath('revisions.0.reason', 'Corrected from the meter photo');

        $this->assertCount(1, $revisions->json('revisions'));
        $history = $this->actingAs($admin, 'web')->getJson("/api/utilities/meters/{$meterId}")->assertOk();
        $this->assertSame('75.250000', $history->json('readings.1.consumption_since_previous'));
        $this->assertSame('24.750000', $history->json('readings.2.consumption_since_previous'));
        $this->assertDatabaseCount('utility_reading_revisions', 1);
    }

    public function test_private_meter_and_readings_require_exact_module_and_resource_grants_even_from_admins(): void
    {
        $admin = $this->admin();
        $owner = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $viewer = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $this->grant($admin, ['view' => true]);
        $this->grant($owner, ['view' => true, 'create' => true, 'edit' => true, 'manage' => true]);
        $this->grant($viewer, ['view' => true, 'edit' => true]);
        app(ModuleManagementService::class)->setEnabled($admin, 'utilities', true);

        $meter = $this->actingAs($owner, 'web')->postJson('/api/utilities/meters', [
            'name' => 'Private water', 'meter_type' => 'water', 'visibility' => 'private',
        ])->assertCreated();
        $meterId = (int) $meter->json('meter.id');
        $readingId = (int) $this->read($owner, $meterId, '2026-10-01', '8.125')->json('reading.id');

        $this->actingAs($admin, 'web')->getJson("/api/utilities/meters/{$meterId}")->assertNotFound();
        $this->actingAs($viewer, 'web')->getJson("/api/utilities/meters/{$meterId}")->assertNotFound();
        $this->actingAs($viewer, 'web')->patchJson("/api/utilities/readings/{$readingId}", [
            'reading_date' => '2026-10-01', 'value' => '9', 'note' => null, 'reason' => 'Corrected meter entry',
        ])->assertNotFound();

        $access = app(ModuleAccessService::class);
        $access->grantPrivateResourceAccess($owner, UtilityMeter::query()->findOrFail($meterId), $viewer, ModuleCapability::View);
        $access->grantPrivateResourceAccess($owner, UtilityReading::query()->findOrFail($readingId), $viewer, ModuleCapability::View);
        $access->grantPrivateResourceAccess($owner, UtilityMeter::query()->findOrFail($meterId), $viewer, ModuleCapability::Edit);
        $access->grantPrivateResourceAccess($owner, UtilityReading::query()->findOrFail($readingId), $viewer, ModuleCapability::Edit);

        $this->actingAs($viewer, 'web')->getJson("/api/utilities/meters/{$meterId}")
            ->assertOk()->assertJsonPath('readings.0.value', '8.125000');
        $this->actingAs($viewer, 'web')->patchJson("/api/utilities/readings/{$readingId}", [
            'reading_date' => '2026-10-01', 'value' => '9', 'note' => null, 'reason' => 'Corrected meter entry',
        ])->assertOk();
        $this->actingAs($admin, 'web')->getJson("/api/utilities/meters/{$meterId}")->assertNotFound();

        $this->grant($viewer, ['view' => false, 'edit' => true]);
        $this->actingAs($viewer, 'web')->getJson("/api/utilities/meters/{$meterId}")->assertForbidden();
        $this->actingAs($viewer, 'web')->patchJson("/api/utilities/readings/{$readingId}", [
            'reading_date' => '2026-10-01', 'value' => '10', 'note' => null, 'reason' => 'Try without view',
        ])->assertOk();

        app(ModuleManagementService::class)->setEnabled($admin, 'utilities', false);
        $this->actingAs($owner, 'web')->getJson('/api/utilities/meters')->assertNotFound();
        $this->actingAs($owner, 'web')->postJson("/api/utilities/meters/{$meterId}/readings", [
            'reading_date' => '2026-10-02', 'value' => '11',
        ])->assertNotFound();
        app(ModuleManagementService::class)->setEnabled($admin, 'utilities', true);
        $this->actingAs($owner, 'web')->getJson("/api/utilities/meters/{$meterId}")->assertOk();
    }

    public function test_uploaded_meter_photos_are_stored_privately_and_authorized_per_reading(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $owner = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $this->grant($admin, ['view' => true]);
        $this->grant($owner, ['view' => true, 'create' => true]);
        app(ModuleManagementService::class)->setEnabled($admin, 'utilities', true);
        $meterId = (int) $this->actingAs($owner, 'web')->postJson('/api/utilities/meters', [
            'name' => 'Photo meter', 'meter_type' => 'gas', 'visibility' => 'private',
        ])->assertCreated()->json('meter.id');

        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/vN8AAAAASUVORK5CYII=', true);
        $response = $this->actingAs($owner, 'web')->post("/api/utilities/meters/{$meterId}/readings", [
            'reading_date' => '2026-10-01',
            'value' => '15.5',
            'photo' => UploadedFile::fake()->createWithContent('meter.png', $png),
        ], ['Accept' => 'application/json']);
        $response->assertCreated();
        $readingId = (int) $response->json('reading.id');
        $photoPath = UtilityReading::query()->findOrFail($readingId)->photo_path;
        $this->assertNotNull($photoPath);
        Storage::disk('local')->assertExists($photoPath);

        $this->actingAs($admin, 'web')->get("/api/utilities/readings/{$readingId}/photo")->assertNotFound();
        $this->actingAs($owner, 'web')->get("/api/utilities/readings/{$readingId}/photo")
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    }

    private function read(User $member, int $meterId, string $date, string $value): TestResponse
    {
        return $this->actingAs($member, 'web')->postJson("/api/utilities/meters/{$meterId}/readings", [
            'reading_date' => $date,
            'value' => $value,
        ])->assertCreated();
    }

    private function admin(): User
    {
        HouseholdSettings::query()->whereKey(1)->update(['setup_completed_at' => now()]);

        return User::factory()->create([
            'name' => 'Utilities Admin',
            'email' => 'utilities-admin@example.test',
            'is_admin' => true,
            'is_active' => true,
        ]);
    }

    /** @param array{view?: bool, create?: bool, edit?: bool, manage?: bool} $capabilities */
    private function grant(User $member, array $capabilities): void
    {
        ModuleUserPermission::query()->updateOrCreate(
            ['user_id' => $member->id, 'module_key' => 'utilities'],
            [
                'can_view' => $capabilities['view'] ?? false,
                'can_create' => $capabilities['create'] ?? false,
                'can_edit' => $capabilities['edit'] ?? false,
                'can_manage' => $capabilities['manage'] ?? false,
            ],
        );
    }
}
