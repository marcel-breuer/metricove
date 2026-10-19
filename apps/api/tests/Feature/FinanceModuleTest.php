<?php

namespace Tests\Feature;

use App\Enums\ModuleCapability;
use App\Models\FinanceCategory;
use App\Models\FinancialTransaction;
use App\Models\HouseholdSettings;
use App\Models\ModuleUserPermission;
use App\Models\User;
use App\Services\Modules\ModuleAccessService;
use App\Services\Modules\ModuleManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class FinanceModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_categories_and_transactions_support_crud_and_exact_decimal_amounts(): void
    {
        $admin = $this->admin();
        $this->grant($admin, ['view' => true, 'create' => true, 'edit' => true, 'manage' => true]);
        $this->enableFinance($admin);

        $category = $this->category($admin, 'expense');
        $categoryId = (int) $category->json('category.id');
        $this->actingAs($admin, 'web')->patchJson("/api/finance/categories/{$categoryId}", ['name' => 'Food and groceries'])
            ->assertOk()->assertJsonPath('category.name', 'Food and groceries');

        $transaction = $this->transaction($admin, $categoryId, '0.10');
        $transactionId = (int) $transaction->json('transaction.id');
        $secondTransactionId = (int) $this->transaction($admin, $categoryId, '0.20')->json('transaction.id');

        $this->actingAs($admin, 'web')->postJson('/api/finance/transactions', [
            'finance_category_id' => $categoryId, 'type' => 'expense', 'description' => 'Invalid decimal',
            'amount' => '0.001', 'currency' => 'EUR', 'occurred_on' => '2026-10-20', 'visibility' => 'private',
        ])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->actingAs($admin, 'web')->postJson('/api/finance/transactions', [
            'finance_category_id' => $categoryId, 'type' => 'income', 'description' => 'Wrong category type',
            'amount' => '1.00', 'currency' => 'EUR', 'occurred_on' => '2026-10-20', 'visibility' => 'private',
        ])->assertUnprocessable()->assertJsonValidationErrors('finance_category_id');

        $this->actingAs($admin, 'web')->patchJson("/api/finance/transactions/{$transactionId}", [
            'description' => 'Corrected coffee', 'amount' => '0.15',
        ])->assertOk()->assertJsonPath('transaction.amount', '0.15')->assertJsonPath('transaction.description', 'Corrected coffee');
        $this->actingAs($admin, 'web')->getJson('/api/finance/transactions?month=2026-10')
            ->assertOk()->assertJsonCount(2, 'transactions');

        $this->actingAs($admin, 'web')->deleteJson("/api/finance/transactions/{$transactionId}")->assertOk();
        $this->actingAs($admin, 'web')->deleteJson("/api/finance/transactions/{$secondTransactionId}")->assertOk();
        $this->actingAs($admin, 'web')->deleteJson("/api/finance/categories/{$categoryId}")->assertOk();
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount('finance_categories', 0);
    }

    public function test_monthly_budgets_sum_visible_expenses_exactly_by_currency_and_month(): void
    {
        $admin = $this->admin();
        $this->grant($admin, ['view' => true, 'create' => true, 'edit' => true, 'manage' => true]);
        $this->enableFinance($admin);
        $categoryId = (int) $this->category($admin, 'expense')->json('category.id');
        $this->transaction($admin, $categoryId, '0.10', '2026-10-03');
        $this->transaction($admin, $categoryId, '0.20', '2026-10-12');
        $this->transaction($admin, $categoryId, '4.50', '2026-09-30');
        $this->transaction($admin, $categoryId, '1.25', '2026-10-14', 'USD');

        $budget = $this->actingAs($admin, 'web')->postJson('/api/finance/budgets', [
            'finance_category_id' => $categoryId, 'amount' => '1.00', 'currency' => 'eur',
            'period_start' => '2026-10-01', 'visibility' => 'private',
        ])->assertCreated();
        $budgetId = (int) $budget->json('budget.id');
        $this->actingAs($admin, 'web')->postJson('/api/finance/budgets', [
            'finance_category_id' => $categoryId, 'amount' => '1.00', 'currency' => 'EUR',
            'period_start' => '2026-10-15', 'visibility' => 'private',
        ])->assertUnprocessable()->assertJsonValidationErrors('period_start');

        $overview = $this->actingAs($admin, 'web')->getJson('/api/finance/overview?month=2026-10')->assertOk();
        $eur = collect($overview->json('totals'))->firstWhere('currency', 'EUR');
        $usd = collect($overview->json('totals'))->firstWhere('currency', 'USD');
        $this->assertSame(['income' => '0.00', 'expenses' => '0.30', 'net' => '-0.30'], [
            'income' => $eur['income'], 'expenses' => $eur['expenses'], 'net' => $eur['net'],
        ]);
        $this->assertSame('1.25', $usd['expenses']);
        $this->assertCount(1, $overview->json('budgets'));
        $this->assertSame('0.30', $overview->json('budgets.0.spent'));
        $this->assertSame('0.70', $overview->json('budgets.0.remaining'));

        $this->actingAs($admin, 'web')->patchJson("/api/finance/budgets/{$budgetId}", ['amount' => '0.25'])
            ->assertOk()->assertJsonPath('budget.amount', '0.25');
        $this->actingAs($admin, 'web')->deleteJson("/api/finance/budgets/{$budgetId}")->assertOk();
    }

    public function test_recurring_entries_record_idempotent_occurrences_and_advance_month_end_dates(): void
    {
        $admin = $this->admin();
        $this->grant($admin, ['view' => true, 'create' => true, 'edit' => true, 'manage' => true]);
        $this->enableFinance($admin);
        $categoryId = (int) $this->category($admin, 'expense')->json('category.id');

        $entry = $this->actingAs($admin, 'web')->postJson('/api/finance/recurring', [
            'finance_category_id' => $categoryId, 'type' => 'expense', 'description' => 'Rent',
            'amount' => '900.00', 'currency' => 'EUR', 'frequency' => 'monthly',
            'starts_on' => '2026-01-31', 'next_due_on' => '2026-01-31', 'ends_on' => '2026-03-31',
            'visibility' => 'private',
        ])->assertCreated();
        $entryId = (int) $entry->json('recurring_entry.id');

        $this->actingAs($admin, 'web')->postJson("/api/finance/recurring/{$entryId}/record", [
            'occurrence_on' => '2026-01-31', 'occurred_on' => '2026-02-01',
        ])->assertCreated()->assertJsonPath('transaction.recurring_occurrence_on', '2026-01-31');
        $this->assertDatabaseHas('finance_recurring_entries', ['id' => $entryId, 'next_due_on' => '2026-02-28']);

        $this->actingAs($admin, 'web')->postJson("/api/finance/recurring/{$entryId}/record", [
            'occurrence_on' => '2026-01-31',
        ])->assertOk()->assertJsonPath('message', 'Recurring occurrence was already recorded.');
        $this->assertDatabaseCount('financial_transactions', 1);

        $this->actingAs($admin, 'web')->postJson("/api/finance/recurring/{$entryId}/record", [
            'occurrence_on' => '2026-02-28',
        ])->assertCreated();
        $this->assertDatabaseHas('finance_recurring_entries', ['id' => $entryId, 'next_due_on' => '2026-03-31']);
        $this->actingAs($admin, 'web')->postJson("/api/finance/recurring/{$entryId}/record", [
            'occurrence_on' => '2026-03-31',
        ])->assertCreated();
        $this->assertDatabaseHas('finance_recurring_entries', ['id' => $entryId, 'next_due_on' => null, 'is_active' => false]);
        $this->assertDatabaseCount('financial_transactions', 3);

        $this->actingAs($admin, 'web')->patchJson("/api/finance/recurring/{$entryId}", ['is_active' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('next_due_on');
        $this->actingAs($admin, 'web')->deleteJson("/api/finance/recurring/{$entryId}")->assertOk();
        $this->assertDatabaseCount('financial_transactions', 3);
    }

    public function test_private_data_stays_hidden_from_admins_and_members_and_module_disable_preserves_records(): void
    {
        $admin = $this->admin();
        $owner = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $viewer = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $this->grant($admin, ['view' => true, 'manage' => true]);
        $this->grant($owner, ['view' => true, 'create' => true, 'edit' => true, 'manage' => true]);
        $this->grant($viewer, ['view' => true, 'edit' => true]);
        $this->enableFinance($admin);
        $categoryId = (int) $this->category($owner, 'expense')->json('category.id');
        $transactionId = (int) $this->transaction($owner, $categoryId, '45.00')->json('transaction.id');

        $this->actingAs($admin, 'web')->getJson('/api/finance/transactions')->assertOk()->assertJsonCount(0, 'transactions');
        $this->actingAs($viewer, 'web')->getJson('/api/finance/transactions')->assertOk()->assertJsonCount(0, 'transactions');
        $this->actingAs($admin, 'web')->patchJson("/api/finance/transactions/{$transactionId}", ['amount' => '1.00'])->assertNotFound();

        app(ModuleAccessService::class)->grantPrivateResourceAccess(
            $owner,
            FinancialTransaction::query()->findOrFail($transactionId),
            $viewer,
            ModuleCapability::View,
        );
        app(ModuleAccessService::class)->grantPrivateResourceAccess(
            $owner,
            FinancialTransaction::query()->findOrFail($transactionId),
            $viewer,
            ModuleCapability::Edit,
        );
        $this->actingAs($viewer, 'web')->getJson('/api/finance/transactions')
            ->assertOk()->assertJsonCount(1, 'transactions')
            ->assertJsonPath('transactions.0.amount', '45.00')
            ->assertJsonPath('transactions.0.category', null)
            ->assertJsonPath('transactions.0.finance_category_id', null);
        $this->actingAs($viewer, 'web')->patchJson("/api/finance/transactions/{$transactionId}", ['description' => 'Updated private-category transaction'])
            ->assertOk()
            ->assertJsonPath('transaction.description', 'Updated private-category transaction')
            ->assertJsonPath('transaction.category', null)
            ->assertJsonPath('transaction.finance_category_id', null);
        app(ModuleAccessService::class)->grantPrivateResourceAccess(
            $owner,
            FinanceCategory::query()->findOrFail($categoryId),
            $viewer,
            ModuleCapability::View,
        );
        $this->actingAs($viewer, 'web')->getJson('/api/finance/transactions')
            ->assertOk()->assertJsonPath('transactions.0.category.name', 'Groceries');
        $this->actingAs($admin, 'web')->getJson('/api/finance/transactions')->assertOk()->assertJsonCount(0, 'transactions');

        app(ModuleManagementService::class)->setEnabled($admin, 'finance', false);
        $this->actingAs($owner, 'web')->getJson('/api/finance/transactions')->assertNotFound();
        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertDatabaseCount('finance_categories', 1);
        app(ModuleManagementService::class)->setEnabled($admin, 'finance', true);
        $this->actingAs($owner, 'web')->getJson('/api/finance/transactions')->assertOk()->assertJsonCount(1, 'transactions');
    }

    public function test_shared_budget_counts_shared_spending_without_revealing_private_transactions(): void
    {
        $admin = $this->admin();
        $owner = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $viewer = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $this->grant($admin, ['view' => true, 'manage' => true]);
        $this->grant($owner, ['view' => true, 'create' => true, 'edit' => true]);
        $this->grant($viewer, ['view' => true]);
        $this->enableFinance($admin);

        $categoryId = (int) $this->actingAs($admin, 'web')->postJson('/api/finance/categories', [
            'type' => 'expense', 'name' => 'Household groceries', 'visibility' => 'household',
        ])->assertCreated()->json('category.id');
        $this->actingAs($admin, 'web')->postJson('/api/finance/budgets', [
            'finance_category_id' => $categoryId, 'amount' => '100.00', 'currency' => 'EUR',
            'period_start' => '2026-10-01', 'visibility' => 'household',
        ])->assertCreated();
        $this->actingAs($admin, 'web')->postJson('/api/finance/transactions', [
            'finance_category_id' => $categoryId, 'type' => 'expense', 'description' => 'Shared supplies',
            'amount' => '20.00', 'currency' => 'EUR', 'occurred_on' => '2026-10-10', 'visibility' => 'household',
        ])->assertCreated();
        $this->actingAs($owner, 'web')->postJson('/api/finance/transactions', [
            'finance_category_id' => $categoryId, 'type' => 'expense', 'description' => 'Private groceries',
            'amount' => '50.00', 'currency' => 'EUR', 'occurred_on' => '2026-10-12', 'visibility' => 'private',
        ])->assertCreated();

        $this->actingAs($viewer, 'web')->getJson('/api/finance/overview?month=2026-10')
            ->assertOk()->assertJsonPath('budgets.0.spent', '20.00');
        $this->actingAs($viewer, 'web')->getJson('/api/finance/transactions?month=2026-10')
            ->assertOk()->assertJsonCount(1, 'transactions')->assertJsonPath('transactions.0.description', 'Shared supplies');
        $ownerOverview = $this->actingAs($owner, 'web')->getJson('/api/finance/overview?month=2026-10')->assertOk();
        $this->assertSame('70.00', $ownerOverview->json('totals.0.expenses'));
        $this->assertSame('20.00', $ownerOverview->json('budgets.0.spent'));
    }

    private function category(User $member, string $type): TestResponse
    {
        return $this->actingAs($member, 'web')->postJson('/api/finance/categories', [
            'type' => $type, 'name' => $type === 'expense' ? 'Groceries' : 'Salary', 'visibility' => 'private',
        ])->assertCreated();
    }

    private function transaction(User $member, int $categoryId, string $amount, string $date = '2026-10-20', string $currency = 'eur'): TestResponse
    {
        return $this->actingAs($member, 'web')->postJson('/api/finance/transactions', [
            'finance_category_id' => $categoryId, 'type' => 'expense', 'description' => 'Coffee',
            'amount' => $amount, 'currency' => $currency, 'occurred_on' => $date, 'visibility' => 'private',
        ])->assertCreated();
    }

    private function admin(): User
    {
        HouseholdSettings::query()->whereKey(1)->update(['setup_completed_at' => now()]);

        return User::factory()->create([
            'name' => 'Finance Admin', 'email' => 'finance-admin@example.test', 'is_admin' => true, 'is_active' => true,
        ]);
    }

    private function enableFinance(User $admin): void
    {
        app(ModuleManagementService::class)->setEnabled($admin, 'finance', true);
    }

    /** @param array{view?: bool, create?: bool, edit?: bool, manage?: bool} $capabilities */
    private function grant(User $member, array $capabilities): void
    {
        ModuleUserPermission::query()->updateOrCreate(
            ['user_id' => $member->id, 'module_key' => 'finance'],
            [
                'can_view' => $capabilities['view'] ?? false,
                'can_create' => $capabilities['create'] ?? false,
                'can_edit' => $capabilities['edit'] ?? false,
                'can_manage' => $capabilities['manage'] ?? false,
            ],
        );
    }
}
