<?php

namespace Tests\Feature;

use App\Contracts\MeterPhotoOcr;
use App\Models\HouseholdSettings;
use App\Models\ModuleUserPermission;
use App\Models\User;
use App\Models\UtilityMeter;
use App\Models\UtilityReading;
use App\Services\Import\TesseractMeterPhotoOcr;
use App\Services\Modules\ModuleManagementService;
use App\Services\Modules\ModuleStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ImportModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_csv_preview_validates_then_imports_once_with_a_stable_idempotent_result(): void
    {
        $admin = $this->configuredAdmin();
        $meterId = $this->meter($admin);
        $this->reading($admin, $meterId, '2026-10-01', '100');
        $csv = "reading_date,value,note\n2026-10-02,105.25,\"Imported meter, with comma\"\n2026-10-01,100,\n";
        $mapping = ['reading_date' => 0, 'value' => 1, 'note' => 2];

        $preview = $this->actingAs($admin, 'web')->post(
            "/api/import/utilities/meters/{$meterId}/readings/preview",
            ['file' => $this->csvFile($csv), 'mapping' => $mapping],
            ['Accept' => 'application/json'],
        )->assertOk()
            ->assertJsonPath('ready_count', 1)
            ->assertJsonPath('skipped_count', 1)
            ->assertJsonPath('errors', []);

        $previewToken = (string) $preview->json('preview_token');
        $this->assertNotSame('', $previewToken);
        $this->assertDatabaseCount('utility_readings', 1);
        $this->assertDatabaseCount('import_runs', 0);

        $idempotencyKey = '8aa6fbb0-e367-4e62-9a7b-55b2c7b643e4';
        $body = [
            'file' => $this->csvFile($csv),
            'preview_token' => $previewToken,
            'confirmed' => '1',
            'mapping' => $mapping,
        ];
        $headers = ['Accept' => 'application/json', 'Idempotency-Key' => $idempotencyKey];

        $this->actingAs($admin, 'web')->post(
            "/api/import/utilities/meters/{$meterId}/readings/confirm",
            $body,
            $headers,
        )->assertOk()
            ->assertJsonPath('created_count', 1)
            ->assertJsonPath('skipped_count', 1);

        $this->actingAs($admin, 'web')->post(
            "/api/import/utilities/meters/{$meterId}/readings/confirm",
            [
                'file' => $this->csvFile($csv),
                'preview_token' => $previewToken,
                'confirmed' => '1',
                'mapping' => $mapping,
            ],
            $headers,
        )->assertOk()
            ->assertJsonPath('created_count', 1)
            ->assertJsonPath('skipped_count', 1);

        $differentCsv = "reading_date,value,note\n2026-10-02,106,Different payload\n";
        $differentPreview = $this->actingAs($admin, 'web')->post(
            "/api/import/utilities/meters/{$meterId}/readings/preview",
            ['file' => $this->csvFile($differentCsv), 'mapping' => $mapping],
            ['Accept' => 'application/json'],
        )->assertOk();
        $this->actingAs($admin, 'web')->post(
            "/api/import/utilities/meters/{$meterId}/readings/confirm",
            [
                'file' => $this->csvFile($differentCsv),
                'preview_token' => $differentPreview->json('preview_token'),
                'confirmed' => '1',
                'mapping' => $mapping,
            ],
            $headers,
        )->assertStatus(409);

        $this->assertDatabaseCount('utility_readings', 2);
        $this->assertDatabaseCount('import_runs', 1);
        $this->assertDatabaseHas('utility_readings', [
            'utility_meter_id' => $meterId,
            'reading_date' => '2026-10-02',
            'value' => '105.250000',
            'note' => 'Imported meter, with comma',
        ]);
        $this->assertFalse(app(ModuleStateService::class)->isEnabled('ai'));
    }

    public function test_invalid_or_changed_csv_never_writes_readings(): void
    {
        $admin = $this->configuredAdmin();
        $meterId = $this->meter($admin);
        $invalidCsv = "reading_date,value,note\n2026-10-01,-1,Negative reading\n";
        $mapping = ['reading_date' => 0, 'value' => 1, 'note' => 2];

        $preview = $this->actingAs($admin, 'web')->post(
            "/api/import/utilities/meters/{$meterId}/readings/preview",
            ['file' => $this->csvFile($invalidCsv), 'mapping' => $mapping],
            ['Accept' => 'application/json'],
        )->assertOk()->assertJsonPath('ready_count', 0);

        $this->assertNotEmpty($preview->json('errors'));
        $this->assertDatabaseCount('utility_readings', 0);

        $this->actingAs($admin, 'web')->post(
            "/api/import/utilities/meters/{$meterId}/readings/confirm",
            [
                'file' => $this->csvFile($invalidCsv),
                'preview_token' => $preview->json('preview_token'),
                'confirmed' => '1',
                'mapping' => $mapping,
            ],
            ['Accept' => 'application/json', 'Idempotency-Key' => 'a98c6ebd-5d1e-40cb-881e-2b406e6d3839'],
        )->assertUnprocessable();

        $validCsv = "reading_date,value,note\n2026-10-02,1,valid\n";
        $validPreview = $this->actingAs($admin, 'web')->post(
            "/api/import/utilities/meters/{$meterId}/readings/preview",
            ['file' => $this->csvFile($validCsv), 'mapping' => $mapping],
            ['Accept' => 'application/json'],
        )->assertOk();
        $changedCsv = "reading_date,value,note\n2026-10-02,2,changed\n";

        $this->actingAs($admin, 'web')->post(
            "/api/import/utilities/meters/{$meterId}/readings/confirm",
            [
                'file' => $this->csvFile($changedCsv),
                'preview_token' => $validPreview->json('preview_token'),
                'confirmed' => '1',
                'mapping' => $mapping,
            ],
            ['Accept' => 'application/json', 'Idempotency-Key' => '378c2158-2439-44c9-a339-5207e1a93312'],
        )->assertUnprocessable();

        $this->assertDatabaseCount('utility_readings', 0);
        $this->assertDatabaseCount('import_runs', 0);
    }

    public function test_csv_columns_can_be_mapped_and_the_mapping_is_bound_to_preview_and_idempotency(): void
    {
        $admin = $this->configuredAdmin();
        $meterId = $this->meter($admin);
        $csv = "meter_label,register,date,description\nKitchen,104.5,2026-10-02,Imported from another tool\n";
        $file = $this->csvFile($csv);

        $columns = $this->actingAs($admin, 'web')->post(
            "/api/import/utilities/meters/{$meterId}/readings/preview",
            ['file' => $file],
            ['Accept' => 'application/json'],
        )->assertOk()
            ->assertJsonPath('mapping_required', true)
            ->assertJsonPath('columns', ['meter_label', 'register', 'date', 'description'])
            ->assertJsonPath('suggested_mapping.reading_date', 2)
            ->assertJsonPath('suggested_mapping.value', 1)
            ->assertJsonPath('suggested_mapping.note', 3);

        $this->assertDatabaseCount('utility_readings', 0);
        $mapping = ['reading_date' => 2, 'value' => 1, 'note' => 3];
        $preview = $this->actingAs($admin, 'web')->post(
            "/api/import/utilities/meters/{$meterId}/readings/preview",
            ['file' => $this->csvFile($csv), 'mapping' => $mapping],
            ['Accept' => 'application/json'],
        )->assertOk()
            ->assertJsonPath('mapping_required', false)
            ->assertJsonPath('rows.0.reading_date', '2026-10-02')
            ->assertJsonPath('rows.0.value', '104.5')
            ->assertJsonPath('rows.0.note', 'Imported from another tool');

        $idempotencyKey = 'c93a0d93-f46c-4083-8d0e-5f2759e00b70';
        $confirm = fn (array $selectedMapping, string $token) => $this->actingAs($admin, 'web')->post(
            "/api/import/utilities/meters/{$meterId}/readings/confirm",
            [
                'file' => $this->csvFile($csv),
                'preview_token' => $token,
                'confirmed' => '1',
                'mapping' => $selectedMapping,
            ],
            ['Accept' => 'application/json', 'Idempotency-Key' => $idempotencyKey],
        );

        $confirm($mapping, $preview->json('preview_token'))->assertOk()->assertJsonPath('created_count', 1);

        $differentMapping = ['reading_date' => 2, 'value' => 1, 'note' => null];
        $differentPreview = $this->actingAs($admin, 'web')->post(
            "/api/import/utilities/meters/{$meterId}/readings/preview",
            ['file' => $this->csvFile($csv), 'mapping' => $differentMapping],
            ['Accept' => 'application/json'],
        )->assertOk();
        $confirm($differentMapping, $differentPreview->json('preview_token'))->assertStatus(409);

        $this->assertDatabaseCount('utility_readings', 1);
        $this->assertDatabaseHas('utility_readings', [
            'utility_meter_id' => $meterId,
            'reading_date' => '2026-10-02',
            'value' => '104.500000',
            'note' => 'Imported from another tool',
        ]);
    }

    public function test_export_includes_only_visible_readings_and_does_not_bypass_private_meter_access(): void
    {
        $admin = $this->configuredAdmin();
        $owner = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $meterId = $this->meter($admin, 'household');
        $this->reading($admin, $meterId, '2026-10-01', '100');
        UtilityReading::query()->where('utility_meter_id', $meterId)->update(['note' => '=1+1']);
        UtilityReading::query()->create([
            'utility_meter_id' => $meterId,
            'owner_user_id' => $owner->id,
            'created_by_user_id' => $owner->id,
            'is_private' => true,
            'reading_date' => '2026-10-02',
            'value' => '9999',
            'note' => 'PRIVATE_EXPORT_SENTINEL',
        ]);
        $privateMeter = UtilityMeter::query()->create([
            'owner_user_id' => $owner->id,
            'created_by_user_id' => $owner->id,
            'name' => 'Private meter',
            'meter_type' => 'water',
            'unit' => 'm³',
            'is_private' => true,
        ]);

        $response = $this->actingAs($admin, 'web')->get("/api/import/utilities/meters/{$meterId}/readings.csv");
        $response->assertOk()->assertDownload("utility-meter-{$meterId}-readings.csv");
        $content = $response->streamedContent();
        $this->assertStringContainsString('100.000000', $content);
        $this->assertStringContainsString("'=1+1", $content);
        $this->assertStringNotContainsString('9999', $content);
        $this->assertStringNotContainsString('PRIVATE_EXPORT_SENTINEL', $content);

        $this->actingAs($admin, 'web')->get("/api/import/utilities/meters/{$privateMeter->id}/readings.csv")->assertNotFound();

        app(ModuleManagementService::class)->setEnabled($admin, 'import', false);
        $this->actingAs($admin, 'web')->get("/api/import/utilities/meters/{$meterId}/readings.csv")->assertNotFound();
        app(ModuleManagementService::class)->setEnabled($admin, 'import', true);
        app(ModuleManagementService::class)->setEnabled($admin, 'utilities', false);
        $this->actingAs($admin, 'web')->get("/api/import/utilities/meters/{$meterId}/readings.csv")->assertNotFound();
        $this->assertDatabaseCount('utility_readings', 2);
    }

    public function test_local_photo_ocr_only_suggests_a_value_and_requires_the_existing_save_action(): void
    {
        $admin = $this->configuredAdmin();
        $meterId = $this->meter($admin);
        $this->app->instance(MeterPhotoOcr::class, new class implements MeterPhotoOcr
        {
            public function suggest(UploadedFile $photo): ?string
            {
                return '1234.567';
            }
        });
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/vN8AAAAASUVORK5CYII=', true);

        $suggestion = $this->actingAs($admin, 'web')->post(
            "/api/import/utilities/meters/{$meterId}/reading-suggestions",
            ['photo' => UploadedFile::fake()->createWithContent('meter.png', $png)],
            ['Accept' => 'application/json'],
        )->assertOk()
            ->assertJsonPath('value', '1234.567')
            ->assertJsonPath('needs_confirmation', true)
            ->assertJsonPath('provider', 'local');

        $this->assertDatabaseCount('utility_readings', 0);
        $this->assertDatabaseCount('import_runs', 0);

        $this->actingAs($admin, 'web')->postJson("/api/utilities/meters/{$meterId}/readings", [
            'reading_date' => '2026-10-01',
            'value' => $suggestion->json('value'),
        ])->assertCreated();
        $this->assertDatabaseCount('utility_readings', 1);

        $this->assertSame('1234.500', TesseractMeterPhotoOcr::singleCandidate("Register\n001234,500\n"));
        $this->assertNull(TesseractMeterPhotoOcr::singleCandidate("Serial 1234\nReading 9999\n"));
    }

    public function test_private_meter_ocr_is_not_available_to_an_admin_without_the_owner_grant(): void
    {
        $admin = $this->configuredAdmin();
        $owner = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $this->grant($owner, 'utilities', ['view' => true, 'create' => true]);
        $meterId = (int) $this->actingAs($owner, 'web')->postJson('/api/utilities/meters', [
            'name' => 'Owner meter', 'meter_type' => 'water', 'visibility' => 'private',
        ])->assertCreated()->json('meter.id');
        $this->assertNotNull($admin->id);
        $this->app->instance(MeterPhotoOcr::class, new class implements MeterPhotoOcr
        {
            public function suggest(UploadedFile $photo): ?string
            {
                return '1';
            }
        });
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/vN8AAAAASUVORK5CYII=', true);

        $this->actingAs($admin, 'web')->post(
            "/api/import/utilities/meters/{$meterId}/reading-suggestions",
            ['photo' => UploadedFile::fake()->createWithContent('meter.png', $png)],
            ['Accept' => 'application/json'],
        )->assertNotFound();
    }

    private function configuredAdmin(): User
    {
        HouseholdSettings::query()->whereKey(1)->update(['setup_completed_at' => now()]);
        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);

        foreach (['import', 'utilities', 'ai'] as $module) {
            $this->grant($admin, $module, ['view' => true, 'create' => true, 'edit' => true, 'manage' => true]);
        }

        $modules = app(ModuleManagementService::class);
        $modules->setEnabled($admin, 'import', true);
        $modules->setEnabled($admin, 'utilities', true);
        $modules->setEnabled($admin, 'ai', false);

        return $admin;
    }

    private function meter(User $admin, string $visibility = 'private'): int
    {
        return (int) $this->actingAs($admin, 'web')->postJson('/api/utilities/meters', [
            'name' => 'Main water', 'meter_type' => 'water', 'visibility' => $visibility,
        ])->assertCreated()->json('meter.id');
    }

    private function reading(User $member, int $meterId, string $date, string $value): void
    {
        $this->actingAs($member, 'web')->postJson("/api/utilities/meters/{$meterId}/readings", [
            'reading_date' => $date, 'value' => $value,
        ])->assertCreated();
    }

    private function csvFile(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('readings.csv', $content);
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
}
