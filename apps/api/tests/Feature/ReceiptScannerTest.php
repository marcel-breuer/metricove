<?php

namespace Tests\Feature;

use App\Models\HouseholdSettings;
use App\Models\ModuleUserPermission;
use App\Models\ReceiptDocument;
use App\Models\User;
use App\Services\Modules\ModuleManagementService;
use App\Services\Modules\ModuleStateService;
use App\Services\Receipts\LocalReceiptOcr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ReceiptScannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_finance_receipt_preview_is_reviewed_saved_privately_and_idempotent(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $this->enable($admin, ['receipts', 'finance']);
        $categoryId = $this->financeCategory($admin);
        $this->mockOcr();
        $file = $this->imageFile('invoice.png');

        $preview = $this->actingAs($admin, 'web')->post('/api/receipts/preview', [
            'file' => $file,
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('needs_confirmation', true)
            ->assertJsonPath('suggestions.merchant', 'Werkstatt Nord')
            ->assertJsonPath('suggestions.invoice_number', 'RN-2026-104')
            ->assertJsonPath('suggestions.invoice_date', '2026-10-29')
            ->assertJsonPath('suggestions.amount', '1234.56')
            ->assertJsonPath('suggestions.currency', 'EUR')
            ->assertJsonPath('suggestions.tax_amount', '197.10');

        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount('receipt_documents', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('receipts'));

        $payload = [
            'file' => $file,
            'preview_token' => $preview->json('preview_token'),
            'confirmed' => '1',
            'target_type' => 'finance',
            'finance_category_id' => $categoryId,
            'description' => 'Workshop invoice',
            'merchant' => 'Werkstatt Nord',
            'invoice_number' => 'RN-2026-104',
            'invoice_date' => '2026-10-29',
            'amount' => '1234.56',
            'currency' => 'EUR',
            'tax_amount' => '197.10',
            'reviewed_fields' => ['merchant', 'fuel_liters', 'fuel_unit_price', 'fuel_odometer_value'],
        ];
        $headers = [
            'Accept' => 'application/json',
            'Idempotency-Key' => 'c690c9f1-a81e-4fd2-9cad-531dd06505dc',
        ];

        $created = $this->actingAs($admin, 'web')->post('/api/receipts/confirm', $payload, $headers)
            ->assertCreated()
            ->assertJsonPath('receipt.target_type', 'finance')
            ->assertJsonPath('receipt.merchant', 'Werkstatt Nord')
            ->assertJsonPath('target.type', 'finance')
            ->assertJsonPath('target.record.payee', 'Werkstatt Nord');
        $receiptId = (int) $created->json('receipt.id');
        $receipt = ReceiptDocument::query()->findOrFail($receiptId);

        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertDatabaseHas('financial_transactions', [
            'id' => $receipt->financial_transaction_id,
            'owner_user_id' => $admin->id,
            'is_private' => true,
            'finance_category_id' => $categoryId,
            'type' => 'expense',
            'occurred_on' => '2026-10-29',
            'amount' => '1234.56',
        ]);
        $this->assertDatabaseHas('receipt_documents', [
            'id' => $receiptId,
            'owner_user_id' => $admin->id,
            'invoice_number' => 'RN-2026-104',
            'tax_amount' => '197.10',
        ]);
        Storage::disk('local')->assertExists($receipt->file_path);

        $this->actingAs($admin, 'web')->post('/api/receipts/confirm', $payload, $headers)
            ->assertOk()
            ->assertJsonPath('receipt.id', $receiptId);
        $changedPayload = [...$payload, 'amount' => '1235.00'];
        $this->actingAs($admin, 'web')->post('/api/receipts/confirm', $changedPayload, $headers)
            ->assertStatus(409);
        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertDatabaseCount('receipt_documents', 1);

        $this->actingAs($admin, 'web')->deleteJson('/api/receipts/'.$receiptId)
            ->assertOk();
        Storage::disk('local')->assertMissing($receipt->file_path);
        $this->assertDatabaseCount('receipt_documents', 0);
        $this->assertDatabaseCount('financial_transactions', 1);
    }

    public function test_vehicle_receipt_creates_a_one_time_cost_without_import_module_access(): void
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
            'amount' => '64.30',
            'currency' => 'EUR',
            'tax_amount' => '10.26',
            'fuel_liters' => '39.20',
            'fuel_unit_price' => '1.6400',
            'fuel_odometer_value' => '81234',
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
            'amount' => '64.30',
            'fuel_liters' => '39.20',
            'fuel_unit_price' => '1.6400',
            'fuel_odometer_value' => '81234.000',
        ]);
        $this->assertDatabaseHas('receipt_documents', [
            'id' => $saved->json('receipt.id'),
            'vehicle_cost_id' => $saved->json('target.id'),
            'financial_transaction_id' => null,
            'owner_user_id' => $admin->id,
        ]);
        $this->assertFalse(app(ModuleStateService::class)->isEnabled('import'));
    }

    public function test_receipt_files_remain_private_from_another_administrator(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $owner = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $this->enable($admin, ['receipts', 'finance']);
        $categoryId = $this->financeCategory($owner);
        $this->mockOcr();
        $file = $this->imageFile('private-invoice.png');
        $preview = $this->actingAs($owner, 'web')->post('/api/receipts/preview', [
            'file' => $file,
        ], ['Accept' => 'application/json'])->assertOk();
        $saved = $this->confirmFinanceReceipt($owner, $file, $preview->json('preview_token'), $categoryId);
        $receiptId = (int) $saved->json('receipt.id');

        $this->actingAs($admin, 'web')->getJson('/api/receipts')
            ->assertOk()->assertJsonCount(0, 'receipts');
        $this->actingAs($admin, 'web')->get('/api/receipts/'.$receiptId.'/file')
            ->assertNotFound();
    }

    public function test_receipt_capability_and_target_module_are_rechecked_before_confirmation(): void
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
            'target_type' => 'finance',
            'finance_category_id' => 1,
            'description' => 'Unpermitted expense',
            'invoice_date' => '2026-10-29',
            'amount' => '12.00',
            'currency' => 'EUR',
            'reviewed_fields' => ['merchant', 'invoice_number', 'tax_amount', 'fuel_liters', 'fuel_unit_price', 'fuel_odometer_value'],
        ], [
            'Accept' => 'application/json',
            'Idempotency-Key' => 'b0d1e2b0-7771-43b4-9600-91762a8f585b',
        ])->assertForbidden();

        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount('receipt_documents', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('receipts'));
    }

    public function test_owner_can_share_and_revoke_a_receipt_without_sharing_its_private_finance_entry(): void
    {
        Storage::fake('local');
        $owner = $this->admin();
        $viewer = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $this->enable($owner, ['receipts', 'finance']);
        $noReceiptAccess = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $viewerWithoutManage = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        ModuleUserPermission::query()->updateOrCreate(
            ['user_id' => $viewerWithoutManage->id, 'module_key' => 'receipts'],
            ['can_view' => true, 'can_create' => false, 'can_edit' => false, 'can_manage' => false],
        );
        $categoryId = $this->financeCategory($owner);
        $this->mockOcr();
        $file = $this->imageFile('shared-invoice.png');
        $preview = $this->actingAs($owner, 'web')->post('/api/receipts/preview', [
            'file' => $file,
        ], ['Accept' => 'application/json'])->assertOk();
        $created = $this->confirmFinanceReceipt($owner, $file, $preview->json('preview_token'), $categoryId);
        $receiptId = (int) $created->json('receipt.id');
        $transactionId = (int) $created->json('target.id');

        ModuleUserPermission::query()->updateOrCreate(
            ['user_id' => $viewer->id, 'module_key' => 'receipts'],
            ['can_view' => true, 'can_create' => false, 'can_edit' => true, 'can_manage' => true],
        );
        ModuleUserPermission::query()->where('user_id', $viewer->id)->where('module_key', 'finance')->update([
            'can_view' => false,
            'can_create' => false,
            'can_edit' => false,
            'can_manage' => false,
        ]);

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

        $this->assertDatabaseHas('financial_transactions', [
            'id' => $transactionId,
            'owner_user_id' => $owner->id,
            'is_private' => true,
        ]);

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

    public function test_archive_only_receipts_can_later_link_to_existing_or_new_finance_entries(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $this->enable($admin, ['receipts', 'finance']);
        $categoryId = $this->financeCategory($admin);
        $existing = $this->actingAs($admin, 'web')->postJson('/api/finance/transactions', [
            'finance_category_id' => $categoryId,
            'type' => 'expense',
            'description' => 'Workshop invoice',
            'payee' => 'Werkstatt Nord',
            'amount' => '1234.56',
            'currency' => 'EUR',
            'occurred_on' => '2026-10-29',
            'visibility' => 'private',
        ])->assertCreated();

        $this->mockOcr();
        $file = $this->imageFile('archive-only.png');
        $preview = $this->actingAs($admin, 'web')->post('/api/receipts/preview', ['file' => $file], ['Accept' => 'application/json'])->assertOk();
        $archived = $this->actingAs($admin, 'web')->post('/api/receipts/confirm', [
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
            'reviewed_fields' => ['merchant', 'fuel_liters', 'fuel_unit_price', 'fuel_odometer_value'],
        ], ['Accept' => 'application/json', 'Idempotency-Key' => '0bb1a3ae-d04d-41f5-9081-761bf64f4d27'])
            ->assertCreated()
            ->assertJsonPath('receipt.target_type', null)
            ->assertJsonPath('receipt.target_linked', false)
            ->assertJsonPath('target', null);
        $receiptId = (int) $archived->json('receipt.id');
        $this->assertDatabaseCount('financial_transactions', 1);

        $this->actingAs($admin, 'web')->getJson('/api/receipts/targets?target_type=finance&search=workshop')
            ->assertOk()
            ->assertJsonPath('targets.0.id', $existing->json('transaction.id'))
            ->assertJsonPath('create_categories.0.id', $categoryId);
        $this->actingAs($admin, 'web')->patchJson('/api/receipts/'.$receiptId.'/target', [
            'target_type' => 'finance',
            'target_record_id' => $existing->json('transaction.id'),
        ])->assertOk()
            ->assertJsonPath('receipt.target_type', 'finance')
            ->assertJsonPath('receipt.target_record_id', $existing->json('transaction.id'))
            ->assertJsonPath('receipt.target_category', 'finance:'.$categoryId);

        $newReceiptId = $this->createArchiveReceipt($admin, 'later-entry.pdf', [
            'merchant' => 'Different merchant',
            'invoice_number' => 'DIFF-1',
            'invoice_date' => '2026-10-27',
            'amount' => '21.50',
            'currency' => 'EUR',
        ]);
        $this->actingAs($admin, 'web')->postJson('/api/receipts/'.$newReceiptId.'/target', [
            'target_type' => 'finance',
            'finance_category_id' => $categoryId,
        ])->assertCreated()
            ->assertJsonPath('receipt.target_type', 'finance')
            ->assertJsonPath('target.type', 'finance')
            ->assertJsonPath('target.record.description', 'Invoice from Different merchant');

        $this->assertDatabaseCount('financial_transactions', 2);
        $this->actingAs($admin, 'web')->getJson('/api/receipts?target_type=finance&category=finance:'.$categoryId.'&sharing_status=private')
            ->assertOk()->assertJsonCount(2, 'receipts');
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

    private function financeCategory(User $member): int
    {
        return (int) $this->actingAs($member, 'web')->postJson('/api/finance/categories', [
            'type' => 'expense',
            'name' => 'Vehicle expenses',
            'visibility' => 'private',
        ])->assertCreated()->json('category.id');
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

    private function confirmFinanceReceipt(User $member, UploadedFile $file, string $previewToken, int $categoryId): TestResponse
    {
        return $this->actingAs($member, 'web')->post('/api/receipts/confirm', [
            'file' => $file,
            'preview_token' => $previewToken,
            'confirmed' => '1',
            'target_type' => 'finance',
            'finance_category_id' => $categoryId,
            'description' => 'Workshop invoice',
            'merchant' => 'Werkstatt Nord',
            'invoice_number' => 'RN-2026-104',
            'invoice_date' => '2026-10-29',
            'amount' => '45.67',
            'currency' => 'EUR',
            'tax_amount' => '7.30',
            'reviewed_fields' => ['merchant', 'fuel_liters', 'fuel_unit_price', 'fuel_odometer_value'],
        ], [
            'Accept' => 'application/json',
            'Idempotency-Key' => 'cb5027ca-0e80-4964-9b77-453c66397883',
        ])->assertCreated();
    }
}
