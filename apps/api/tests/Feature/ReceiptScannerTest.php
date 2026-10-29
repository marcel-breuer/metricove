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
        ], [
            'Accept' => 'application/json',
            'Idempotency-Key' => 'b0d1e2b0-7771-43b4-9600-91762a8f585b',
        ])->assertForbidden();

        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount('receipt_documents', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('receipts'));
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
            public function extract(UploadedFile $file): string
            {
                return "Werkstatt Nord\nRechnungsnummer: RN-2026-104\nRechnungsdatum: 29.10.2026\nGesamtbetrag: 1.234,56 EUR\nMwSt. 19%: 197,10 EUR";
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
        ], [
            'Accept' => 'application/json',
            'Idempotency-Key' => 'cb5027ca-0e80-4964-9b77-453c66397883',
        ])->assertCreated();
    }
}
