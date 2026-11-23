<?php

namespace Tests\Feature;

use App\Models\HouseholdSettings;
use App\Models\ModuleUserPermission;
use App\Models\ReceiptDocument;
use App\Models\User;
use App\Services\Modules\ModuleManagementService;
use App\Services\Receipts\LocalReceiptOcr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReceiptScannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_vehicle_receipt_creates_a_one_time_cost_without_import_module(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $this->enable($admin, ['receipts', 'vehicles']);
        $vehicle = $this->actingAs($admin, 'web')->postJson('/api/vehicles', [
            'name' => 'Daily car',
            'make' => 'Example',
            'model' => 'One',
            'fuel_type' => 'petrol',
            'odometer_unit' => 'km',
            'visibility' => 'private',
        ])->assertCreated();
        $vehicleId = (int) $vehicle->json('vehicle.id');
        $this->mockOcr();
        $file = $this->imageFile('fuel.png');
        $preview = $this->actingAs($admin, 'web')->post('/api/receipts/preview', [
            'file' => $file,
        ], ['Accept' => 'application/json'])->assertOk();

        $saved = $this->actingAs($admin, 'web')->post('/api/receipts/confirm', [
            'file' => $file,
            'preview_token' => $preview->json('preview_token'),
            'confirmed' => '1',
            'target_type' => 'vehicle',
            'vehicle_id' => $vehicleId,
            'vehicle_category' => 'fuel',
            'description' => 'Fuel stop',
            'merchant' => 'Werkstatt Nord',
            'invoice_number' => 'RN-2026-104',
            'invoice_date' => '2026-10-29',
            'currency' => 'EUR',
            'tax_amount' => '10.26',
            'fuel_liters' => '39.20',
            'fuel_unit_price' => '1.6400',
            'fuel_odometer_value' => '81234',
            'fuel_full_tank' => true,
            'reviewed_fields' => ['merchant', 'fuel_liters', 'fuel_unit_price', 'fuel_odometer_value'],
        ], [
            'Accept' => 'application/json',
            'Idempotency-Key' => 'c16aa959-77aa-4a15-b434-f1a3f90979cc',
        ])->assertCreated()->assertJsonPath('receipt.target_type', 'vehicle');

        $this->assertDatabaseHas('vehicle_costs', [
            'id' => $saved->json('target.id'),
            'vehicle_id' => $vehicleId,
            'category' => 'fuel',
            'period' => 'once',
            'period_start' => '2026-10-29',
            'amount' => '64.29',
            'fuel_liters' => '39.20',
            'fuel_unit_price' => '1.6400',
            'fuel_odometer_value' => '81234.000',
            'fuel_full_tank' => true,
        ]);
        $this->assertDatabaseHas('receipt_documents', [
            'id' => $saved->json('receipt.id'),
            'vehicle_cost_id' => $saved->json('target.id'),
            'financial_transaction_id' => null,
            'owner_user_id' => $admin->id,
            'amount' => '64.29',
            'fuel_liters' => '39.20',
            'fuel_unit_price' => '1.6400',
            'fuel_odometer_value' => '81234.000',
            'fuel_full_tank' => true,
        ]);
        $this->assertArrayNotHasKey('import', config('modules.catalog'));
    }

    public function test_receipt_files_remain_private_from_another_administrator(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $owner = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $this->enable($admin, ['receipts']);
        $receiptId = $this->createArchiveReceipt($owner, 'private-invoice.pdf', [
            'merchant' => 'Werkstatt Nord',
            'invoice_number' => 'RN-2026-104',
            'invoice_date' => '2026-10-29',
            'amount' => '45.67',
            'currency' => 'EUR',
        ]);

        $this->actingAs($admin, 'web')->getJson('/api/receipts')
            ->assertOk()->assertJsonCount(0, 'receipts');
        $this->actingAs($admin, 'web')->get('/api/receipts/'.$receiptId.'/file')
            ->assertNotFound();
    }

    public function test_receipt_capability_is_rechecked_before_confirmation(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $this->enable($admin, ['receipts']);
        $this->mockOcr();
        $file = $this->imageFile('invoice.png');
        ModuleUserPermission::query()->where('user_id', $admin->id)->where('module_key', 'receipts')->update(['can_create' => false]);

        $this->actingAs($admin, 'web')->post('/api/receipts/preview', [
            'file' => $file,
        ], ['Accept' => 'application/json'])->assertForbidden();

        ModuleUserPermission::query()->where('user_id', $admin->id)->where('module_key', 'receipts')->update(['can_create' => true]);
        $preview = $this->actingAs($admin, 'web')->post('/api/receipts/preview', [
            'file' => $file,
        ], ['Accept' => 'application/json'])->assertOk();

        $this->actingAs($admin, 'web')->post('/api/receipts/confirm', [
            'file' => $file,
            'preview_token' => $preview->json('preview_token'),
            'confirmed' => '1',
            'target_type' => 'archive',
            'description' => 'Archived invoice',
            'invoice_date' => '2026-10-29',
            'amount' => '12.00',
            'currency' => 'EUR',
            'reviewed_fields' => ['merchant', 'invoice_number', 'tax_amount', 'fuel_liters', 'fuel_unit_price', 'fuel_odometer_value'],
        ], [
            'Accept' => 'application/json',
            'Idempotency-Key' => 'b0d1e2b0-7771-43b4-9600-91762a8f585b',
        ])->assertCreated();

        $this->assertDatabaseCount('receipt_documents', 1);
    }

    public function test_owner_can_share_and_revoke_a_receipt(): void
    {
        Storage::fake('local');
        $owner = $this->admin();
        $viewer = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $this->enable($owner, ['receipts']);
        $noReceiptAccess = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $viewerWithoutManage = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        ModuleUserPermission::query()->updateOrCreate(
            ['user_id' => $viewerWithoutManage->id, 'module_key' => 'receipts'],
            ['can_view' => true, 'can_create' => false, 'can_edit' => false, 'can_manage' => false],
        );
        $receiptId = $this->createArchiveReceipt($owner, 'shared-invoice.pdf', [
            'merchant' => 'Werkstatt Nord',
            'invoice_number' => 'RN-2026-104',
            'invoice_date' => '2026-10-29',
            'amount' => '45.67',
            'currency' => 'EUR',
        ]);

        ModuleUserPermission::query()->updateOrCreate(
            ['user_id' => $viewer->id, 'module_key' => 'receipts'],
            ['can_view' => true, 'can_create' => false, 'can_edit' => true, 'can_manage' => true],
        );

        $this->actingAs($viewer, 'web')->getJson('/api/receipts')
            ->assertOk()->assertJsonCount(0, 'receipts');
        $this->actingAs($viewer, 'web')->get('/api/receipts/'.$receiptId.'/file')->assertNotFound();
        $this->actingAs($viewerWithoutManage, 'web')->patchJson('/api/receipts/'.$receiptId.'/sharing', [
            'shared_with_household' => true,
        ])->assertForbidden();
        $this->actingAs($noReceiptAccess, 'web')->getJson('/api/receipts')->assertForbidden();

        $this->actingAs($owner, 'web')->patchJson('/api/receipts/'.$receiptId.'/sharing', [
            'shared_with_household' => true,
        ])->assertOk()
            ->assertJsonPath('receipt.shared_with_household', true)
            ->assertJsonPath('receipt.is_owner', true);

        $this->assertDatabaseHas('receipt_documents', ['id' => $receiptId, 'is_private' => false]);
        $this->actingAs($viewer, 'web')->getJson('/api/receipts')
            ->assertOk()
            ->assertJsonCount(1, 'receipts')
            ->assertJsonPath('receipts.0.shared_with_household', true)
            ->assertJsonPath('receipts.0.is_owner', false)
            ->assertJsonPath('receipts.0.target_linked', false)
            ->assertJsonPath('receipts.0.target_type', null)
            ->assertJsonPath('receipts.0.target_record_id', null);
        $this->actingAs($noReceiptAccess, 'web')->getJson('/api/receipts')->assertForbidden();
        $this->actingAs($viewerWithoutManage, 'web')->getJson('/api/receipts')
            ->assertOk()->assertJsonCount(1, 'receipts');
        $this->actingAs($viewer, 'web')->get('/api/receipts/'.$receiptId.'/file')
            ->assertOk()->assertHeaderContains('Content-Disposition', 'attachment');
        $this->actingAs($viewer, 'web')->get('/api/receipts/'.$receiptId.'/preview')
            ->assertOk()->assertHeaderContains('Content-Disposition', 'inline');
        $this->actingAs($viewer, 'web')->deleteJson('/api/receipts/'.$receiptId)->assertNotFound();
        $this->actingAs($viewer, 'web')->patchJson('/api/receipts/'.$receiptId.'/sharing', [
            'shared_with_household' => false,
        ])->assertNotFound();

        $this->actingAs($owner, 'web')->patchJson('/api/receipts/'.$receiptId.'/sharing', [
            'shared_with_household' => false,
        ])->assertOk()->assertJsonPath('receipt.shared_with_household', false);
        $this->assertDatabaseHas('receipt_documents', ['id' => $receiptId, 'is_private' => true]);
        $this->actingAs($viewer, 'web')->getJson('/api/receipts')
            ->assertOk()->assertJsonCount(0, 'receipts');
        $this->actingAs($viewer, 'web')->get('/api/receipts/'.$receiptId.'/file')->assertNotFound();
    }

    public function test_receipt_archive_searches_and_filters_visible_rows_by_date_amount_and_currency(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $this->enable($admin, ['receipts']);
        $this->createArchiveReceipt($admin, 'garage-north.png', [
            'merchant' => 'Garage Nord',
            'invoice_number' => 'GN-1001',
            'invoice_date' => '2026-10-12',
            'amount' => '74.50',
            'currency' => 'EUR',
        ]);
        $this->createArchiveReceipt($admin, 'market.png', [
            'merchant' => 'Market West',
            'invoice_number' => 'MW-2002',
            'invoice_date' => '2026-10-15',
            'amount' => '49.99',
            'currency' => 'EUR',
        ]);
        $this->createArchiveReceipt($admin, 'garage-east.png', [
            'merchant' => 'Garage Ost',
            'invoice_number' => 'GO-3003',
            'invoice_date' => '2026-09-30',
            'amount' => '75.00',
            'currency' => 'USD',
        ]);

        $this->actingAs($admin, 'web')->getJson('/api/receipts?search=garage&date_from=2026-10-01&date_to=2026-10-31&min_amount=50&max_amount=80&currency=EUR')
            ->assertOk()
            ->assertJsonCount(1, 'receipts')
            ->assertJsonPath('receipts.0.merchant', 'Garage Nord')
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('pagination.per_page', 30)
            ->assertJsonPath('currencies', ['EUR', 'USD']);

        $this->actingAs($admin, 'web')->getJson('/api/receipts?min_amount=50')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('currency');
    }

    public function test_receipt_targets_reject_the_removed_finance_module(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $this->enable($admin, ['receipts']);
        $receiptId = $this->createArchiveReceipt($admin, 'archived.pdf', [
            'merchant' => 'Werkstatt Nord',
            'invoice_number' => 'RN-2026-104',
            'invoice_date' => '2026-10-29',
            'amount' => '1234.56',
            'currency' => 'EUR',
        ]);

        $this->actingAs($admin, 'web')->getJson('/api/receipts/targets?target_type=finance')
            ->assertUnprocessable();
        $this->actingAs($admin, 'web')->patchJson('/api/receipts/'.$receiptId.'/target', [
            'target_type' => 'finance',
            'target_record_id' => 1,
        ])->assertUnprocessable();
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_duplicate_receipt_warning_requires_acknowledgement_but_allows_saving(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $this->enable($admin, ['receipts']);
        $this->createArchiveReceipt($admin, 'prior-invoice.pdf', [
            'merchant' => 'Werkstatt Nord',
            'invoice_number' => 'RN-2026-104',
            'invoice_date' => '2026-10-29',
            'amount' => '1234.56',
            'currency' => 'EUR',
        ]);
        $this->mockOcr();
        $file = $this->imageFile('possible-duplicate.png');
        $preview = $this->actingAs($admin, 'web')->post('/api/receipts/preview', ['file' => $file], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonCount(1, 'duplicate_matches')
            ->assertJsonFragment(['merchant_invoice_number']);
        $payload = [
            'file' => $file,
            'preview_token' => $preview->json('preview_token'),
            'confirmed' => '1',
            'target_type' => 'archive',
            'description' => 'Workshop invoice',
            'merchant' => 'Werkstatt Nord',
            'invoice_number' => 'RN-2026-104',
            'invoice_date' => '2026-10-29',
            'amount' => '1234.56',
            'currency' => 'EUR',
            'tax_amount' => '197.10',
            'reviewed_fields' => ['merchant', 'invoice_number', 'tax_amount', 'fuel_liters', 'fuel_unit_price', 'fuel_odometer_value'],
        ];

        $this->actingAs($admin, 'web')->post('/api/receipts/confirm', $payload, [
            'Accept' => 'application/json',
            'Idempotency-Key' => '3beea34f-d2e9-40cb-a360-3f3e1ca8534e',
        ])->assertUnprocessable()->assertJsonValidationErrors('duplicate_acknowledged');

        $this->actingAs($admin, 'web')->post('/api/receipts/confirm', [...$payload, 'duplicate_acknowledged' => '1'], [
            'Accept' => 'application/json',
            'Idempotency-Key' => '2d47af0e-c775-4e7e-99bf-2531726b20fb',
        ])->assertCreated()->assertJsonPath('receipt.target_type', null);
        $this->assertDatabaseCount('receipt_documents', 2);
    }

    /** @param array{merchant: string, invoice_number: string, invoice_date: string, amount: string, currency: string} $values */
    private function createArchiveReceipt(User $owner, string $fileName, array $values): int
    {
        $path = 'receipts/'.$fileName;
        Storage::disk('local')->put($path, 'private invoice');
        $receipt = ReceiptDocument::query()->create([
            'owner_user_id' => $owner->id,
            'is_private' => true,
            'file_path' => $path,
            'mime_type' => 'application/pdf',
            'merchant' => $values['merchant'],
            'invoice_number' => $values['invoice_number'],
            'invoice_date' => $values['invoice_date'],
            'amount' => $values['amount'],
            'currency' => $values['currency'],
            'idempotency_key' => (string) Str::uuid(),
            'file_digest' => hash('sha256', $fileName),
            'request_fingerprint' => hash('sha256', $values['invoice_number']),
        ]);

        return (int) $receipt->id;
    }

    private function admin(): User
    {
        HouseholdSettings::query()->whereKey(1)->update(['setup_completed_at' => now()]);

        return User::factory()->create(['is_admin' => true, 'is_active' => true]);
    }

    /** @param list<string> $modules */
    private function enable(User $admin, array $modules): void
    {
        foreach ($modules as $module) {
            app(ModuleManagementService::class)->setEnabled($admin, $module, true);
        }
    }

    private function mockOcr(): void
    {
        $this->app->instance(LocalReceiptOcr::class, new class extends LocalReceiptOcr
        {
            public function extractDetailed(UploadedFile $file): array
            {
                return [
                    'text' => "Werkstatt Nord\nRechnungsnummer: RN-2026-104\nRechnungsdatum: 29.10.2026\nGesamtbetrag: 1.234,56 EUR\nMwSt. 19%: 197,10 EUR",
                    'confidence' => 95,
                ];
            }
        });
    }

    private function imageFile(string $name): UploadedFile
    {
        $contents = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+x3wkAAAAASUVORK5CYII=', true);
        $this->assertIsString($contents);

        return UploadedFile::fake()->createWithContent($name, $contents);
    }

}
