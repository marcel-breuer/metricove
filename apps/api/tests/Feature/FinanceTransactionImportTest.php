<?php

namespace Tests\Feature;

use App\Models\FinanceCategory;
use App\Models\FinancialTransaction;
use App\Models\HouseholdSettings;
use App\Models\ModuleUserPermission;
use App\Models\User;
use App\Services\Modules\ModuleManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class FinanceTransactionImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_csv_preview_maps_categories_then_confirms_with_idempotent_private_transactions(): void
    {
        $admin = $this->configuredAdmin();
        $this->enableImports($admin);
        $expenseCategory = $this->category($admin, 'expense', 'Groceries');
        $incomeCategory = $this->category($admin, 'income', 'Salary');
        $csv = "date,description,amount,currency,category,payee\n2026-10-11,Food shop,20.10,EUR,Groceries,Market\n2026-10-12,Refund,-5.25,EUR,Salary,Store\n";
        $mapping = ['occurred_on' => 0, 'description' => 1, 'amount' => 2, 'currency' => 3, 'category' => 4, 'payee' => 5];

        $preview = $this->actingAs($admin, 'web')->post(
            '/api/import/finance/transactions/preview',
            ['file' => $this->csvFile($csv)],
            ['Accept' => 'application/json'],
        )->assertOk()
            ->assertJsonPath('format', 'csv')
            ->assertJsonPath('mapping_required', true)
            ->assertJsonPath('suggested_mapping.occurred_on', 0)
            ->assertJsonPath('suggested_mapping.amount', 2);
        $this->assertDatabaseCount('financial_transactions', 0);

        $preview = $this->actingAs($admin, 'web')->post(
            '/api/import/finance/transactions/preview',
            ['file' => $this->csvFile($csv), 'mapping' => $mapping],
            ['Accept' => 'application/json'],
        )->assertOk()
            ->assertJsonPath('format', 'csv')
            ->assertJsonPath('ready_count', 2)
            ->assertJsonPath('skipped_count', 0)
            ->assertJsonPath('errors', [])
            ->assertJsonPath('rows.0.type', 'expense')
            ->assertJsonPath('rows.0.amount', '20.10')
            ->assertJsonPath('rows.0.category.name', 'Groceries')
            ->assertJsonPath('rows.1.type', 'income')
            ->assertJsonPath('rows.1.amount', '5.25');
        $this->assertDatabaseCount('financial_transactions', 0);
        $previewToken = (string) $preview->json('preview_token');
        $this->assertNotSame('', $previewToken);

        $idempotencyKey = '780c3b06-603f-4e58-9a4d-3ced6c9c3b09';
        $this->actingAs($admin, 'web')->post(
            '/api/import/finance/transactions/confirm',
            ['file' => $this->csvFile($csv), 'preview_token' => $previewToken, 'confirmed' => '1', 'mapping' => $mapping],
            ['Accept' => 'application/json', 'Idempotency-Key' => $idempotencyKey],
        )->assertOk()->assertJsonPath('created_count', 2)->assertJsonPath('skipped_count', 0);

        $this->actingAs($admin, 'web')->post(
            '/api/import/finance/transactions/confirm',
            ['file' => $this->csvFile($csv), 'preview_token' => $previewToken, 'confirmed' => '1', 'mapping' => $mapping],
            ['Accept' => 'application/json', 'Idempotency-Key' => $idempotencyKey],
        )->assertOk()->assertJsonPath('created_count', 2)->assertJsonPath('skipped_count', 0);

        $this->assertDatabaseCount('financial_transactions', 2);
        $this->assertDatabaseCount('finance_import_runs', 1);
        $this->assertDatabaseCount('finance_imported_transactions', 2);
        $this->assertDatabaseHas('financial_transactions', [
            'owner_user_id' => $admin->id,
            'finance_category_id' => $expenseCategory->id,
            'is_private' => true,
            'type' => 'expense',
            'payee' => 'Market',
            'amount' => '20.10',
            'currency' => 'EUR',
            'occurred_on' => '2026-10-11',
        ]);
        $this->assertDatabaseHas('financial_transactions', [
            'owner_user_id' => $admin->id,
            'finance_category_id' => $incomeCategory->id,
            'type' => 'income',
            'payee' => 'Store',
            'amount' => '5.25',
            'occurred_on' => '2026-10-12',
        ]);

        $this->actingAs($admin, 'web')->post(
            '/api/import/finance/transactions/confirm',
            ['file' => $this->csvFile($csv), 'preview_token' => $previewToken, 'confirmed' => '1', 'mapping' => $mapping],
            ['Accept' => 'application/json', 'Idempotency-Key' => '60f1e43a-c75a-4808-9004-4ad43de5e030'],
        )->assertOk()->assertJsonPath('created_count', 0)->assertJsonPath('skipped_count', 2);
        $this->assertDatabaseCount('financial_transactions', 2);
    }

    public function test_camt053_preview_uses_expense_and_income_defaults_and_confirms_supported_entries(): void
    {
        $admin = $this->configuredAdmin();
        $this->enableImports($admin);
        $expenseCategory = $this->category($admin, 'expense', 'Bills');
        $incomeCategory = $this->category($admin, 'income', 'Salary');
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02">
  <BkToCstmrStmt><Stmt>
    <Ntry><Amt Ccy="EUR">125.50</Amt><CdtDbtInd>DBIT</CdtDbtInd><BookgDt><Dt>2026-10-01</Dt></BookgDt><AcctSvcrRef>REF-D-100</AcctSvcrRef><NtryDtls><TxDtls><Refs><AcctSvcrRef>REF-D-100</AcctSvcrRef></Refs><RltdPties><Cdtr><Pty><Nm>Energy Co</Nm></Pty></Cdtr></RltdPties><RmtInf><Ustrd>October electricity</Ustrd></RmtInf></TxDtls></NtryDtls></Ntry>
    <Ntry><Amt Ccy="EUR">2500.00</Amt><CdtDbtInd>CRDT</CdtDbtInd><BookgDt><Dt>2026-10-02</Dt></BookgDt><NtryDtls><TxDtls><RltdPties><Dbtr><Pty><Nm>Employer</Nm></Pty></Dbtr></RltdPties><RmtInf><Ustrd>October salary</Ustrd></RmtInf></TxDtls></NtryDtls></Ntry>
  </Stmt></BkToCstmrStmt>
</Document>
XML;
        $defaults = ['expense' => (string) $expenseCategory->id, 'income' => (string) $incomeCategory->id];
        $preview = $this->actingAs($admin, 'web')->post(
            '/api/import/finance/transactions/preview',
            ['file' => $this->xmlFile($xml), 'category_defaults' => $defaults],
            ['Accept' => 'application/json'],
        )->assertOk()
            ->assertJsonPath('format', 'camt.053')
            ->assertJsonPath('mapping_required', false)
            ->assertJsonPath('ready_count', 2)
            ->assertJsonPath('errors', [])
            ->assertJsonPath('rows.0.type', 'expense')
            ->assertJsonPath('rows.0.category.name', 'Bills')
            ->assertJsonPath('rows.0.payee', 'Energy Co')
            ->assertJsonPath('rows.1.type', 'income')
            ->assertJsonPath('rows.1.category.name', 'Salary');

        $this->actingAs($admin, 'web')->post(
            '/api/import/finance/transactions/confirm',
            ['file' => $this->xmlFile($xml), 'preview_token' => $preview->json('preview_token'), 'confirmed' => '1', 'category_defaults' => $defaults],
            ['Accept' => 'application/json', 'Idempotency-Key' => '1f94a681-05aa-41f7-969f-332a3c39461e'],
        )->assertOk()->assertJsonPath('created_count', 2);

        $this->assertDatabaseCount('financial_transactions', 2);
        $this->assertDatabaseHas('financial_transactions', ['finance_category_id' => $expenseCategory->id, 'amount' => '125.50', 'type' => 'expense']);
        $this->assertDatabaseHas('financial_transactions', ['finance_category_id' => $incomeCategory->id, 'amount' => '2500.00', 'type' => 'income']);
        $this->assertDatabaseHas('financial_transactions', ['finance_category_id' => $expenseCategory->id, 'payee' => 'Energy Co']);
        $this->assertDatabaseHas('financial_transactions', ['finance_category_id' => $incomeCategory->id, 'payee' => 'Employer']);
    }

    public function test_rows_with_distinct_payees_are_not_collapsed_as_duplicates(): void
    {
        $admin = $this->configuredAdmin();
        $this->enableImports($admin);
        $category = $this->category($admin, 'expense', 'Groceries');
        $csv = "date,description,amount,currency,category,payee\n2026-10-11,Food shop,20.10,EUR,Groceries,Market\n2026-10-11,Food shop,20.10,EUR,Groceries,Bakery\n";
        $mapping = ['occurred_on' => 0, 'description' => 1, 'amount' => 2, 'currency' => 3, 'category' => 4, 'payee' => 5];
        $preview = $this->actingAs($admin, 'web')->post(
            '/api/import/finance/transactions/preview',
            ['file' => $this->csvFile($csv), 'mapping' => $mapping],
            ['Accept' => 'application/json'],
        )->assertOk()
            ->assertJsonPath('ready_count', 2)
            ->assertJsonPath('skipped_count', 0)
            ->assertJsonPath('rows.0.payee', 'Market')
            ->assertJsonPath('rows.1.payee', 'Bakery');

        $this->actingAs($admin, 'web')->post(
            '/api/import/finance/transactions/confirm',
            ['file' => $this->csvFile($csv), 'preview_token' => $preview->json('preview_token'), 'confirmed' => '1', 'mapping' => $mapping],
            ['Accept' => 'application/json', 'Idempotency-Key' => '46427fd3-a2e2-4605-bf6d-e75c30e377c1'],
        )->assertOk()->assertJsonPath('created_count', 2)->assertJsonPath('skipped_count', 0);

        $this->assertDatabaseCount('financial_transactions', 2);
        $this->assertDatabaseHas('financial_transactions', ['finance_category_id' => $category->id, 'description' => 'Food shop', 'payee' => 'Market']);
        $this->assertDatabaseHas('financial_transactions', ['finance_category_id' => $category->id, 'description' => 'Food shop', 'payee' => 'Bakery']);
    }

    public function test_payee_is_available_for_manual_edits_and_import_duplicate_matching(): void
    {
        $admin = $this->configuredAdmin();
        $this->enableImports($admin);
        $category = $this->category($admin, 'expense', 'Groceries');
        $manual = $this->actingAs($admin, 'web')->postJson('/api/finance/transactions', [
            'finance_category_id' => $category->id,
            'type' => 'expense',
            'description' => 'Food shop',
            'payee' => 'Market',
            'amount' => '20.10',
            'currency' => 'EUR',
            'occurred_on' => '2026-10-11',
            'visibility' => 'private',
        ])->assertCreated()->assertJsonPath('transaction.payee', 'Market');
        $this->actingAs($admin, 'web')->patchJson('/api/finance/transactions/'.$manual->json('transaction.id'), ['payee' => '  Market  '])
            ->assertOk()->assertJsonPath('transaction.payee', 'Market');
        $this->actingAs($admin, 'web')->getJson('/api/finance/transactions')
            ->assertOk()->assertJsonPath('transactions.0.payee', 'Market');

        $csv = "date,description,amount,currency,category,payee\n2026-10-11,Food shop,20.10,EUR,Groceries,Market\n2026-10-11,Food shop,20.10,EUR,Groceries,Bakery\n";
        $mapping = ['occurred_on' => 0, 'description' => 1, 'amount' => 2, 'currency' => 3, 'category' => 4, 'payee' => 5];
        $preview = $this->actingAs($admin, 'web')->post(
            '/api/import/finance/transactions/preview',
            ['file' => $this->csvFile($csv), 'mapping' => $mapping],
            ['Accept' => 'application/json'],
        )->assertOk()->assertJsonPath('ready_count', 1)->assertJsonPath('skipped_count', 1);

        $this->actingAs($admin, 'web')->post(
            '/api/import/finance/transactions/confirm',
            ['file' => $this->csvFile($csv), 'preview_token' => $preview->json('preview_token'), 'confirmed' => '1', 'mapping' => $mapping],
            ['Accept' => 'application/json', 'Idempotency-Key' => '0749b205-8272-4578-8135-e852e5e35896'],
        )->assertOk()->assertJsonPath('created_count', 1)->assertJsonPath('skipped_count', 1);

        $this->assertDatabaseCount('financial_transactions', 2);
        $this->assertDatabaseHas('financial_transactions', ['finance_category_id' => $category->id, 'payee' => 'Market']);
        $this->assertDatabaseHas('financial_transactions', ['finance_category_id' => $category->id, 'payee' => 'Bakery']);
    }

    public function test_csv_detects_semicolon_delimiters_and_decimal_commas(): void
    {
        $admin = $this->configuredAdmin();
        $this->enableImports($admin);
        $this->category($admin, 'expense', 'Groceries');
        $csv = "date;description;amount;currency;category\n2026-10-11;Food shop;20,10;EUR;Groceries\n";
        $mapping = ['occurred_on' => 0, 'description' => 1, 'amount' => 2, 'currency' => 3, 'category' => 4];

        $this->actingAs($admin, 'web')->post(
            '/api/import/finance/transactions/preview',
            ['file' => $this->csvFile($csv)],
            ['Accept' => 'application/json'],
        )->assertOk()
            ->assertJsonPath('columns.0', 'date')
            ->assertJsonPath('columns.4', 'category');

        $this->actingAs($admin, 'web')->post(
            '/api/import/finance/transactions/preview',
            ['file' => $this->csvFile($csv), 'mapping' => $mapping],
            ['Accept' => 'application/json'],
        )->assertOk()
            ->assertJsonPath('ready_count', 1)
            ->assertJsonPath('rows.0.amount', '20.10');

        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_confirmation_is_bound_to_the_selected_preview_rows(): void
    {
        $admin = $this->configuredAdmin();
        $this->enableImports($admin);
        $category = $this->category($admin, 'expense', 'Groceries');
        $csv = "date,description,amount,currency,category\n2026-10-11,Shop one,12.34,EUR,Groceries\n2026-10-12,Shop two,19.00,EUR,Groceries\n";
        $mapping = ['occurred_on' => 0, 'description' => 1, 'amount' => 2, 'currency' => 3, 'category' => 4];
        $preview = $this->actingAs($admin, 'web')->post(
            '/api/import/finance/transactions/preview',
            ['file' => $this->csvFile($csv), 'mapping' => $mapping, 'selected_rows' => [2]],
            ['Accept' => 'application/json'],
        )->assertOk()
            ->assertJsonPath('ready_count', 1)
            ->assertJsonPath('selected_rows', [2])
            ->assertJsonPath('rows.0.selected', true)
            ->assertJsonPath('rows.1.selected', false)
            ->assertJsonPath('rows.1.action', 'omit');

        $this->actingAs($admin, 'web')->post(
            '/api/import/finance/transactions/confirm',
            ['file' => $this->csvFile($csv), 'preview_token' => $preview->json('preview_token'), 'confirmed' => '1', 'mapping' => $mapping, 'selected_rows' => [3]],
            ['Accept' => 'application/json', 'Idempotency-Key' => '2090a3c9-346d-4860-b564-770c5e90452f'],
        )->assertStatus(409);

        $this->actingAs($admin, 'web')->post(
            '/api/import/finance/transactions/confirm',
            ['file' => $this->csvFile($csv), 'preview_token' => $preview->json('preview_token'), 'confirmed' => '1', 'mapping' => $mapping, 'selected_rows' => [2]],
            ['Accept' => 'application/json', 'Idempotency-Key' => '2090a3c9-346d-4860-b564-770c5e90452f'],
        )->assertOk()->assertJsonPath('created_count', 1);

        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertDatabaseHas('financial_transactions', ['finance_category_id' => $category->id, 'description' => 'Shop one']);
        $this->assertDatabaseMissing('financial_transactions', ['description' => 'Shop two']);
    }

    public function test_an_explicit_empty_selection_never_defaults_back_to_all_rows(): void
    {
        $admin = $this->configuredAdmin();
        $this->enableImports($admin);
        $this->category($admin, 'expense', 'Groceries');
        $csv = "date,description,amount,currency,category\n2026-10-11,Shop,12.34,EUR,Groceries\n";
        $mapping = ['occurred_on' => 0, 'description' => 1, 'amount' => 2, 'currency' => 3, 'category' => 4];

        $this->actingAs($admin, 'web')->post(
            '/api/import/finance/transactions/preview',
            ['file' => $this->csvFile($csv), 'mapping' => $mapping],
            ['Accept' => 'application/json'],
        )->assertOk()->assertJsonPath('ready_count', 1);

        $preview = $this->actingAs($admin, 'web')->post(
            '/api/import/finance/transactions/preview',
            ['file' => $this->csvFile($csv), 'mapping' => $mapping, 'selection_submitted' => '1', 'selected_rows' => []],
            ['Accept' => 'application/json'],
        )->assertOk()
            ->assertJsonPath('ready_count', 0)
            ->assertJsonPath('selected_rows', [])
            ->assertJsonPath('rows.0.action', 'omit');

        $this->actingAs($admin, 'web')->post(
            '/api/import/finance/transactions/confirm',
            [
                'file' => $this->csvFile($csv),
                'preview_token' => $preview->json('preview_token'),
                'confirmed' => '1',
                'mapping' => $mapping,
                'selection_submitted' => '1',
                'selected_rows' => [],
            ],
            ['Accept' => 'application/json', 'Idempotency-Key' => '00e004bf-6512-4ff6-81f2-2ea7f01a76cc'],
        )->assertOk()->assertJsonPath('created_count', 0);

        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount('finance_import_runs', 1);
    }

    public function test_valid_selected_rows_can_be_imported_while_invalid_rows_are_reported(): void
    {
        $admin = $this->configuredAdmin();
        $this->enableImports($admin);
        $this->category($admin, 'expense', 'Groceries');
        $csv = "date,description,amount,currency,category\n2026-10-11,Unmapped shop,12.34,EUR,Unknown\n2026-10-12,Valid shop,19.00,EUR,Groceries\n";
        $mapping = ['occurred_on' => 0, 'description' => 1, 'amount' => 2, 'currency' => 3, 'category' => 4];
        $preview = $this->actingAs($admin, 'web')->post(
            '/api/import/finance/transactions/preview',
            ['file' => $this->csvFile($csv), 'mapping' => $mapping],
            ['Accept' => 'application/json'],
        )->assertOk()
            ->assertJsonPath('ready_count', 1)
            ->assertJsonPath('rows.0.action', 'invalid')
            ->assertJsonPath('rows.1.action', 'import');
        $this->assertNotEmpty($preview->json('errors'));

        $this->actingAs($admin, 'web')->post(
            '/api/import/finance/transactions/confirm',
            [
                'file' => $this->csvFile($csv),
                'preview_token' => $preview->json('preview_token'),
                'confirmed' => '1',
                'mapping' => $mapping,
                'selected_rows' => [3],
                'selection_submitted' => '1',
            ],
            ['Accept' => 'application/json', 'Idempotency-Key' => '811725fb-312b-4fa3-a3b9-956f29de433e'],
        )->assertOk()->assertJsonPath('created_count', 1);

        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertDatabaseHas('financial_transactions', ['description' => 'Valid shop']);
        $this->assertDatabaseMissing('financial_transactions', ['description' => 'Unmapped shop']);
    }

    public function test_expired_preview_tokens_cannot_create_transactions(): void
    {
        $admin = $this->configuredAdmin();
        $this->enableImports($admin);
        $this->category($admin, 'expense', 'Groceries');
        $csv = "date,description,amount,currency,category\n2026-10-11,Food,12.34,EUR,Groceries\n";
        $mapping = ['occurred_on' => 0, 'description' => 1, 'amount' => 2, 'currency' => 3, 'category' => 4];
        $preview = $this->actingAs($admin, 'web')->post(
            '/api/import/finance/transactions/preview',
            ['file' => $this->csvFile($csv), 'mapping' => $mapping],
            ['Accept' => 'application/json'],
        )->assertOk();

        $this->travel(21)->minutes();
        $this->actingAs($admin, 'web')->post(
            '/api/import/finance/transactions/confirm',
            ['file' => $this->csvFile($csv), 'preview_token' => $preview->json('preview_token'), 'confirmed' => '1', 'mapping' => $mapping],
            ['Accept' => 'application/json', 'Idempotency-Key' => 'f36e9f07-fdbb-456b-b85e-1549b998fe28'],
        )->assertStatus(409);

        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount('finance_import_runs', 0);
    }

    public function test_malformed_csv_and_unsupported_camt_entries_are_reported_without_writes(): void
    {
        $admin = $this->configuredAdmin();
        $this->enableImports($admin);
        $csv = "date,description,amount,currency\n2026-13-40,Invalid date,12.34,EUR\n";
        $mapping = ['occurred_on' => 0, 'description' => 1, 'amount' => 2, 'currency' => 3];

        $this->actingAs($admin, 'web')->post(
            '/api/import/finance/transactions/preview',
            ['file' => $this->csvFile($csv), 'mapping' => $mapping],
            ['Accept' => 'application/json'],
        )->assertOk()
            ->assertJsonPath('ready_count', 0)
            ->assertJsonPath('errors.0.row', 2);

        $unsupported = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02">
  <BkToCstmrStmt><Stmt><Ntry><Amt Ccy="EUR">12.00</Amt><BookgDt><Dt>2026-10-11</Dt></BookgDt></Ntry></Stmt></BkToCstmrStmt>
</Document>
XML;
        $this->actingAs($admin, 'web')->post(
            '/api/import/finance/transactions/preview',
            ['file' => $this->xmlFile($unsupported)],
            ['Accept' => 'application/json'],
        )->assertOk()
            ->assertJsonPath('ready_count', 0)
            ->assertJsonPath('errors.0.row', 2);

        $unsafe = '<!DOCTYPE Document [<!ENTITY secret "external">]><Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02"><BkToCstmrStmt/></Document>';
        $this->actingAs($admin, 'web')->post(
            '/api/import/finance/transactions/preview',
            ['file' => $this->xmlFile($unsafe)],
            ['Accept' => 'application/json'],
        )->assertUnprocessable();

        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_invalid_categories_changed_uploads_and_reused_keys_never_import(): void
    {
        $admin = $this->configuredAdmin();
        $this->enableImports($admin);
        $this->category($admin, 'expense', 'Groceries');
        $csv = "date,description,amount,currency,category\n2026-10-11,Food,12.34,EUR,Missing\n";
        $mapping = ['occurred_on' => 0, 'description' => 1, 'amount' => 2, 'currency' => 3, 'category' => 4];
        $preview = $this->actingAs($admin, 'web')->post(
            '/api/import/finance/transactions/preview',
            ['file' => $this->csvFile($csv), 'mapping' => $mapping],
            ['Accept' => 'application/json'],
        )->assertOk()->assertJsonPath('ready_count', 0);
        $this->assertNotEmpty($preview->json('errors'));
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->actingAs($admin, 'web')->post(
            '/api/import/finance/transactions/confirm',
            ['file' => $this->csvFile($csv), 'preview_token' => $preview->json('preview_token'), 'confirmed' => '1', 'mapping' => $mapping],
            ['Accept' => 'application/json', 'Idempotency-Key' => 'fe0905f4-b320-4ab4-8ed8-142e40a3135f'],
        )->assertUnprocessable();
        $this->assertDatabaseCount('finance_import_runs', 0);

        $valid = "date,description,amount,currency,category\n2026-10-11,Food,12.34,EUR,Groceries\n";
        $validPreview = $this->actingAs($admin, 'web')->post(
            '/api/import/finance/transactions/preview',
            ['file' => $this->csvFile($valid), 'mapping' => $mapping],
            ['Accept' => 'application/json'],
        )->assertOk();
        $changed = "date,description,amount,currency,category\n2026-10-11,Changed,12.34,EUR,Groceries\n";
        $this->actingAs($admin, 'web')->post(
            '/api/import/finance/transactions/confirm',
            ['file' => $this->csvFile($changed), 'preview_token' => $validPreview->json('preview_token'), 'confirmed' => '1', 'mapping' => $mapping],
            ['Accept' => 'application/json', 'Idempotency-Key' => 'fe0905f4-b320-4ab4-8ed8-142e40a3135f'],
        )->assertStatus(409);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_import_enforces_module_capabilities_and_does_not_match_other_members_private_transactions(): void
    {
        $admin = $this->configuredAdmin();
        $this->enableImports($admin);
        $owner = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        ModuleUserPermission::query()->updateOrCreate(
            ['user_id' => $owner->id, 'module_key' => 'finance'],
            ['can_view' => true, 'can_create' => true, 'can_edit' => false, 'can_manage' => false],
        );
        ModuleUserPermission::query()->updateOrCreate(
            ['user_id' => $owner->id, 'module_key' => 'import'],
            ['can_view' => true, 'can_create' => true, 'can_edit' => false, 'can_manage' => false],
        );
        $this->category($admin, 'expense', 'Private groceries');
        $ownerCategory = $this->category($owner, 'expense', 'Private groceries');
        $this->actingAs($owner, 'web')->postJson('/api/finance/transactions', [
            'finance_category_id' => $ownerCategory->id,
            'type' => 'expense',
            'description' => 'Same visible facts',
            'amount' => '9.00',
            'currency' => 'EUR',
            'occurred_on' => '2026-10-11',
            'visibility' => 'private',
        ])->assertCreated();

        $csv = "date,description,amount,currency,category\n2026-10-11,Same visible facts,9.00,EUR,Private groceries\n";
        $mapping = ['occurred_on' => 0, 'description' => 1, 'amount' => 2, 'currency' => 3, 'category' => 4];
        $preview = $this->actingAs($admin, 'web')->post(
            '/api/import/finance/transactions/preview',
            ['file' => $this->csvFile($csv), 'mapping' => $mapping],
            ['Accept' => 'application/json'],
        )->assertOk()->assertJsonPath('ready_count', 1);
        $this->assertSame(0, $preview->json('skipped_count'));
        $this->assertSame(1, FinancialTransaction::query()->count());

        $this->actingAs($owner, 'web')->post(
            '/api/import/finance/transactions/preview',
            ['file' => $this->csvFile($csv), 'mapping' => $mapping],
            ['Accept' => 'application/json'],
        )->assertOk()->assertJsonPath('ready_count', 0)->assertJsonPath('skipped_count', 1);

        ModuleUserPermission::query()->where('user_id', $admin->id)->where('module_key', 'import')->update(['can_create' => false]);
        $this->actingAs($admin, 'web')->post(
            '/api/import/finance/transactions/preview',
            ['file' => $this->csvFile($csv), 'mapping' => $mapping],
            ['Accept' => 'application/json'],
        )->assertForbidden();
        ModuleUserPermission::query()->where('user_id', $admin->id)->where('module_key', 'import')->update(['can_create' => true]);
        app(ModuleManagementService::class)->setEnabled($admin, 'import', false);
        $this->actingAs($admin, 'web')->post(
            '/api/import/finance/transactions/preview',
            ['file' => $this->csvFile($csv), 'mapping' => $mapping],
            ['Accept' => 'application/json'],
        )->assertNotFound();
        $this->assertDatabaseCount('financial_transactions', 1);
    }

    private function configuredAdmin(): User
    {
        HouseholdSettings::query()->whereKey(1)->update(['setup_completed_at' => now()]);

        return User::factory()->create(['is_admin' => true, 'is_active' => true]);
    }

    private function enableImports(User $admin): void
    {
        app(ModuleManagementService::class)->setEnabled($admin, 'finance', true);
        app(ModuleManagementService::class)->setEnabled($admin, 'import', true);
    }

    private function category(User $member, string $type, string $name): FinanceCategory
    {
        $response = $this->actingAs($member, 'web')->postJson('/api/finance/categories', [
            'type' => $type,
            'name' => $name,
            'visibility' => 'private',
        ])->assertCreated();

        return FinanceCategory::query()->findOrFail($response->json('category.id'));
    }

    private function csvFile(string $contents): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('transactions.csv', $contents);
    }

    private function xmlFile(string $contents): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('statement.xml', $contents);
    }
}
