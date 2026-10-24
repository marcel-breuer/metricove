<?php

namespace App\Services\Finance;

use App\Contracts\ModuleResource;
use App\Enums\ModuleCapability;
use App\Models\FinanceBudget;
use App\Models\FinanceCategory;
use App\Models\FinanceRecurringEntry;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Services\Modules\ModuleAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinanceService
{
    private const MODULE = 'finance';

    public function __construct(private readonly ModuleAccessService $access) {}

    /** @return list<array<string, mixed>> */
    public function categories(User $actor): array
    {
        $this->access->authorize($actor, self::MODULE, ModuleCapability::View);

        return $this->visibleCategories($actor, ModuleCapability::View);
    }

    /** @return list<array<string, mixed>> */
    public function importableCategories(User $actor): array
    {
        $this->access->authorize($actor, self::MODULE, ModuleCapability::Create);

        return $this->visibleCategories($actor, ModuleCapability::Create);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createCategory(User $actor, array $data): array
    {
        $isPrivate = $data['visibility'] === 'private';
        $capability = $isPrivate ? ModuleCapability::Create : ModuleCapability::Manage;

        $category = DB::transaction(function () use ($actor, $data, $isPrivate, $capability): FinanceCategory {
            if (! $isPrivate) {
                $this->authorizeSharedManagement($actor);
            }

            $this->access->authorize($actor, self::MODULE, $capability);
            $this->assertCategoryNameAvailable($actor, $data['type'], $data['name'], $isPrivate);
            $category = FinanceCategory::query()->create([
                'owner_user_id' => $isPrivate ? $actor->id : null,
                'created_by_user_id' => $actor->id,
                'is_private' => $isPrivate,
                'type' => $data['type'],
                'name' => trim($data['name']),
            ]);
            $this->access->authorize($actor, self::MODULE, $capability, $category);

            return $category;
        }, 3);

        return $this->presentCategory($category);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateCategory(User $actor, int $categoryId, array $data): array
    {
        $category = DB::transaction(function () use ($actor, $categoryId, $data): FinanceCategory {
            $category = $this->findForMutation($actor, FinanceCategory::class, 'finance_categories', $categoryId);
            $capability = $this->mutationCapability($category);
            $this->authorizeMutation($actor, $capability, $category);
            $this->assertCategoryNameAvailable($actor, $category->type, $data['name'], $category->is_private, $category->id);
            $category->name = trim($data['name']);
            $category->save();

            return $category->fresh();
        }, 3);

        return $this->presentCategory($category);
    }

    public function deleteCategory(User $actor, int $categoryId): void
    {
        DB::transaction(function () use ($actor, $categoryId): void {
            $category = $this->findForMutation($actor, FinanceCategory::class, 'finance_categories', $categoryId);
            $this->authorizeMutation($actor, $this->mutationCapability($category), $category);

            if ($category->transactions()->exists() || $category->recurringEntries()->exists() || $category->budgets()->exists()) {
                throw ValidationException::withMessages(['category' => 'A category used by financial records cannot be deleted.']);
            }

            $category->delete();
        }, 3);
    }

    /** @return list<array<string, mixed>> */
    public function transactions(User $actor, ?string $month = null): array
    {
        $this->access->authorize($actor, self::MODULE, ModuleCapability::View);
        $query = $this->visibleResources($actor, FinancialTransaction::class, 'financial_transactions', [ModuleCapability::View], FinancialTransaction::query())
            ->with('category')
            ->orderByDesc('occurred_on')->orderByDesc('id');

        if ($month !== null) {
            [$start, $end] = $this->monthRange($month);
            $query->whereBetween('occurred_on', [$start, $end]);
        }

        return $query->limit(200)->get()
            ->map(fn (FinancialTransaction $transaction): array => $this->presentTransaction($actor, $transaction, ModuleCapability::View))
            ->all();
    }

    /**
     * Return only transactions the caller may already view, constrained to candidate dates.
     * This supports deterministic import duplicate checks without inspecting another member's private rows.
     *
     * @param  list<string>  $dates
     * @return list<array{occurred_on: string, type: string, amount: string, currency: string, description: string, payee: ?string}>
     */
    public function visibleTransactionsOnDates(User $actor, array $dates): array
    {
        $this->access->authorize($actor, self::MODULE, ModuleCapability::View);
        $dates = array_values(array_unique(array_filter($dates, static fn (string $date): bool => $date !== '')));

        if ($dates === []) {
            return [];
        }

        /** @var Collection<int, FinancialTransaction> $transactions */
        $transactions = $this->visibleResources($actor, FinancialTransaction::class, 'financial_transactions', [ModuleCapability::View], FinancialTransaction::query())
            ->whereIn('occurred_on', $dates)
            ->get(['occurred_on', 'type', 'amount', 'currency', 'description', 'payee']);

        return $transactions->map(static fn (FinancialTransaction $transaction): array => [
            'occurred_on' => substr((string) $transaction->getRawOriginal('occurred_on'), 0, 10),
            'type' => $transaction->type,
            'amount' => (string) $transaction->amount,
            'currency' => $transaction->currency,
            'description' => $transaction->description,
            'payee' => $transaction->payee,
        ])->all();
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createTransaction(User $actor, array $data): array
    {
        $amount = $this->validatedAmount($data['amount']);
        $isPrivate = $data['visibility'] === 'private';
        $capability = $isPrivate ? ModuleCapability::Create : ModuleCapability::Manage;

        $transaction = DB::transaction(function () use ($actor, $data, $amount, $isPrivate, $capability): FinancialTransaction {
            if (! $isPrivate) {
                $this->authorizeSharedManagement($actor);
            }

            $this->access->authorize($actor, self::MODULE, $capability);
            $category = $this->findCategoryForUse($actor, (int) $data['finance_category_id'], $data['type'], $isPrivate, $capability);
            $transaction = FinancialTransaction::query()->create([
                'finance_category_id' => $category->id,
                'owner_user_id' => $isPrivate ? $actor->id : null,
                'created_by_user_id' => $actor->id,
                'is_private' => $isPrivate,
                'type' => $data['type'],
                'description' => trim($data['description']),
                'payee' => $this->normalizedPayee($data['payee'] ?? null),
                'amount' => $amount,
                'currency' => strtoupper($data['currency']),
                'occurred_on' => $data['occurred_on'],
            ]);
            $this->access->authorize($actor, self::MODULE, $capability, $transaction);

            return $transaction->load('category');
        }, 3);

        return $this->presentTransaction($actor, $transaction, $capability);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateTransaction(User $actor, int $transactionId, array $data): array
    {
        $transaction = DB::transaction(function () use ($actor, $transactionId, $data): FinancialTransaction {
            $transaction = $this->findForMutation($actor, FinancialTransaction::class, 'financial_transactions', $transactionId);
            $capability = $this->mutationCapability($transaction);
            $this->authorizeMutation($actor, $capability, $transaction);
            $type = $data['type'] ?? $transaction->type;
            $category = null;

            if (array_key_exists('finance_category_id', $data) || array_key_exists('type', $data)) {
                if (! array_key_exists('finance_category_id', $data) && $type !== $transaction->type) {
                    throw ValidationException::withMessages(['finance_category_id' => 'Choose a category matching the new transaction type.']);
                }

                $categoryId = (int) ($data['finance_category_id'] ?? $transaction->finance_category_id);
                $category = $this->findCategoryForUse($actor, $categoryId, $type, $transaction->is_private, $capability);
            }

            foreach (['type', 'description', 'payee', 'currency', 'occurred_on'] as $field) {
                if (array_key_exists($field, $data)) {
                    $transaction->setAttribute($field, match ($field) {
                        'description' => trim($data[$field]),
                        'payee' => $this->normalizedPayee($data[$field]),
                        default => $data[$field],
                    });
                }
            }

            if (array_key_exists('amount', $data)) {
                $transaction->amount = $this->validatedAmount($data['amount']);
            }

            if ($category instanceof FinanceCategory) {
                $transaction->finance_category_id = $category->id;
            }
            $transaction->save();

            return $transaction->fresh()->load('category');
        }, 3);

        return $this->presentTransaction($actor, $transaction, $this->mutationCapability($transaction));
    }

    public function deleteTransaction(User $actor, int $transactionId): void
    {
        DB::transaction(function () use ($actor, $transactionId): void {
            $transaction = $this->findForMutation($actor, FinancialTransaction::class, 'financial_transactions', $transactionId);
            $this->authorizeMutation($actor, $this->mutationCapability($transaction), $transaction);
            $transaction->delete();
        }, 3);
    }

    /** @return list<array<string, mixed>> */
    public function recurringEntries(User $actor): array
    {
        $this->access->authorize($actor, self::MODULE, ModuleCapability::View);

        return $this->visibleResources($actor, FinanceRecurringEntry::class, 'finance_recurring_entries', [ModuleCapability::View], FinanceRecurringEntry::query())
            ->with('category')
            ->orderByRaw('CASE WHEN next_due_on IS NULL THEN 1 ELSE 0 END')
            ->orderBy('next_due_on')->orderBy('id')
            ->limit(200)->get()
            ->map(fn (FinanceRecurringEntry $entry): array => $this->presentRecurringEntry($actor, $entry, ModuleCapability::View))
            ->all();
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createRecurringEntry(User $actor, array $data): array
    {
        $amount = $this->validatedAmount($data['amount']);
        $isPrivate = $data['visibility'] === 'private';
        $capability = $isPrivate ? ModuleCapability::Create : ModuleCapability::Manage;

        $entry = DB::transaction(function () use ($actor, $data, $amount, $isPrivate, $capability): FinanceRecurringEntry {
            if (! $isPrivate) {
                $this->authorizeSharedManagement($actor);
            }

            $this->access->authorize($actor, self::MODULE, $capability);
            $category = $this->findCategoryForUse($actor, (int) $data['finance_category_id'], $data['type'], $isPrivate, $capability);
            $entry = FinanceRecurringEntry::query()->create([
                'finance_category_id' => $category->id,
                'owner_user_id' => $isPrivate ? $actor->id : null,
                'created_by_user_id' => $actor->id,
                'is_private' => $isPrivate,
                'type' => $data['type'],
                'description' => trim($data['description']),
                'amount' => $amount,
                'currency' => strtoupper($data['currency']),
                'frequency' => $data['frequency'],
                'starts_on' => $data['starts_on'],
                'next_due_on' => $data['next_due_on'],
                'ends_on' => $data['ends_on'] ?? null,
                'is_active' => true,
            ]);
            $this->access->authorize($actor, self::MODULE, $capability, $entry);

            return $entry->load('category');
        }, 3);

        return $this->presentRecurringEntry($actor, $entry, $capability);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateRecurringEntry(User $actor, int $entryId, array $data): array
    {
        $entry = DB::transaction(function () use ($actor, $entryId, $data): FinanceRecurringEntry {
            $entry = $this->findForMutation($actor, FinanceRecurringEntry::class, 'finance_recurring_entries', $entryId);
            $capability = $this->mutationCapability($entry);
            $this->authorizeMutation($actor, $capability, $entry);
            $type = $data['type'] ?? $entry->type;
            $category = null;

            if (array_key_exists('finance_category_id', $data) || array_key_exists('type', $data)) {
                if (! array_key_exists('finance_category_id', $data) && $type !== $entry->type) {
                    throw ValidationException::withMessages(['finance_category_id' => 'Choose a category matching the new entry type.']);
                }

                $categoryId = (int) ($data['finance_category_id'] ?? $entry->finance_category_id);
                $category = $this->findCategoryForUse($actor, $categoryId, $type, $entry->is_private, $capability);
            }

            foreach (['type', 'description', 'currency', 'frequency', 'starts_on', 'next_due_on', 'ends_on', 'is_active'] as $field) {
                if (array_key_exists($field, $data)) {
                    $entry->{$field} = $field === 'description' ? trim($data[$field]) : $data[$field];
                }
            }

            if (array_key_exists('amount', $data)) {
                $entry->amount = $this->validatedAmount($data['amount']);
            }

            if ($category instanceof FinanceCategory) {
                $entry->finance_category_id = $category->id;
            }
            $entry->next_due_on = $entry->next_due_on?->format('Y-m-d');
            $entry->starts_on = $entry->starts_on?->format('Y-m-d');
            $entry->ends_on = $entry->ends_on?->format('Y-m-d');
            $this->assertRecurringDates($entry);
            $entry->save();

            return $entry->fresh()->load('category');
        }, 3);

        return $this->presentRecurringEntry($actor, $entry, $this->mutationCapability($entry));
    }

    public function deleteRecurringEntry(User $actor, int $entryId): void
    {
        DB::transaction(function () use ($actor, $entryId): void {
            $entry = $this->findForMutation($actor, FinanceRecurringEntry::class, 'finance_recurring_entries', $entryId);
            $this->authorizeMutation($actor, $this->mutationCapability($entry), $entry);
            $entry->delete();
        }, 3);
    }

    /** @param array{occurrence_on: string, occurred_on?: string} $data
     * @return array{transaction: array<string, mixed>, created: bool}
     */
    public function recordRecurringOccurrence(User $actor, int $entryId, array $data): array
    {
        return DB::transaction(function () use ($actor, $entryId, $data): array {
            $entry = FinanceRecurringEntry::query()->whereKey($entryId)->lockForUpdate()->firstOrFail();
            $this->access->authorize($actor, self::MODULE, ModuleCapability::Create, $entry);
            $requestedOccurrence = $data['occurrence_on'];
            $existing = FinancialTransaction::query()
                ->where('finance_recurring_entry_id', $entry->id)
                ->where('recurring_occurrence_on', $requestedOccurrence)
                ->with('category')->first();

            if ($existing instanceof FinancialTransaction) {
                $this->access->authorize($actor, self::MODULE, ModuleCapability::Create, $existing);

                return ['transaction' => $this->presentTransaction($actor, $existing, ModuleCapability::Create), 'created' => false];
            }

            if (! $entry->is_active || $entry->next_due_on === null) {
                throw ValidationException::withMessages(['occurrence_on' => 'This recurring entry has no active due occurrence.']);
            }

            if ($requestedOccurrence !== $entry->next_due_on->format('Y-m-d')) {
                throw ValidationException::withMessages(['occurrence_on' => 'Reload this schedule and use its current next due date.']);
            }

            if (! $entry->is_private) {
                $this->authorizeSharedManagement($actor);
            }

            $ownerId = $entry->is_private ? $actor->id : null;
            $transaction = FinancialTransaction::query()->create([
                'finance_category_id' => $entry->finance_category_id,
                'finance_recurring_entry_id' => $entry->id,
                'owner_user_id' => $ownerId,
                'created_by_user_id' => $actor->id,
                'is_private' => $entry->is_private,
                'type' => $entry->type,
                'description' => $entry->description,
                'amount' => $entry->amount,
                'currency' => $entry->currency,
                'occurred_on' => $data['occurred_on'] ?? $requestedOccurrence,
                'recurring_occurrence_on' => $requestedOccurrence,
            ]);
            $this->access->authorize($actor, self::MODULE, ModuleCapability::Create, $transaction);

            $next = $this->nextOccurrence(
                CarbonImmutable::parse($entry->next_due_on->format('Y-m-d')),
                $entry->frequency,
                $entry->starts_on->day,
            );

            if ($entry->ends_on !== null && $next->greaterThan($entry->ends_on)) {
                $entry->next_due_on = null;
                $entry->is_active = false;
            } else {
                $entry->next_due_on = $next->format('Y-m-d');
            }

            $entry->save();

            return ['transaction' => $this->presentTransaction($actor, $transaction->fresh()->load('category'), ModuleCapability::Create), 'created' => true];
        }, 5);
    }

    /** @return list<array<string, mixed>> */
    public function budgets(User $actor, ?string $month = null): array
    {
        $overview = $this->overview($actor, $month ?? now()->format('Y-m'));

        return $overview['budgets'];
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createBudget(User $actor, array $data): array
    {
        $amount = $this->validatedAmount($data['amount']);
        $isPrivate = $data['visibility'] === 'private';
        $capability = $isPrivate ? ModuleCapability::Create : ModuleCapability::Manage;
        $periodStart = $this->periodStart($data['period_start']);

        $budget = DB::transaction(function () use ($actor, $data, $amount, $isPrivate, $capability, $periodStart): FinanceBudget {
            if (! $isPrivate) {
                $this->authorizeSharedManagement($actor);
            }

            $this->access->authorize($actor, self::MODULE, $capability);
            $category = $this->findCategoryForUse($actor, (int) $data['finance_category_id'], 'expense', $isPrivate, $capability);
            $this->assertBudgetAvailable($actor, $category, $isPrivate, $periodStart, strtoupper($data['currency']));
            $budget = FinanceBudget::query()->create([
                'finance_category_id' => $category->id,
                'owner_user_id' => $isPrivate ? $actor->id : null,
                'created_by_user_id' => $actor->id,
                'is_private' => $isPrivate,
                'amount' => $amount,
                'currency' => strtoupper($data['currency']),
                'period_start' => $periodStart,
            ]);
            $this->access->authorize($actor, self::MODULE, $capability, $budget);

            return $budget->load('category');
        }, 3);

        return $this->presentBudget($actor, $budget, null, $capability);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateBudget(User $actor, int $budgetId, array $data): array
    {
        $budget = DB::transaction(function () use ($actor, $budgetId, $data): FinanceBudget {
            $budget = $this->findForMutation($actor, FinanceBudget::class, 'finance_budgets', $budgetId);
            $this->authorizeMutation($actor, $this->mutationCapability($budget), $budget);
            $category = null;

            if (array_key_exists('finance_category_id', $data)) {
                $category = $this->findCategoryForUse(
                    $actor,
                    (int) $data['finance_category_id'],
                    'expense',
                    $budget->is_private,
                    $this->mutationCapability($budget),
                );
            }

            $budgetCategory = $category ?? $budget->category;

            if (! $budgetCategory instanceof FinanceCategory) {
                throw ValidationException::withMessages(['finance_category_id' => 'Choose an available expense category.']);
            }

            $this->assertBudgetAvailable(
                $actor,
                $budgetCategory,
                $budget->is_private,
                array_key_exists('period_start', $data) ? $this->periodStart($data['period_start']) : $budget->period_start->format('Y-m-d'),
                strtoupper($data['currency'] ?? $budget->currency),
                $budget->id,
            );

            if (array_key_exists('amount', $data)) {
                $budget->amount = $this->validatedAmount($data['amount']);
            }

            if (array_key_exists('currency', $data)) {
                $budget->currency = strtoupper($data['currency']);
            }

            if (array_key_exists('period_start', $data)) {
                $budget->period_start = $this->periodStart($data['period_start']);
            }

            if ($category instanceof FinanceCategory) {
                $budget->finance_category_id = $category->id;
            }
            $budget->save();

            return $budget->fresh()->load('category');
        }, 3);

        return $this->presentBudget($actor, $budget, null, $this->mutationCapability($budget));
    }

    public function deleteBudget(User $actor, int $budgetId): void
    {
        DB::transaction(function () use ($actor, $budgetId): void {
            $budget = $this->findForMutation($actor, FinanceBudget::class, 'finance_budgets', $budgetId);
            $this->authorizeMutation($actor, $this->mutationCapability($budget), $budget);
            $budget->delete();
        }, 3);
    }

    /** @return array{month: string, totals: list<array<string, string>>, budgets: list<array<string, mixed>>} */
    public function overview(User $actor, string $month): array
    {
        $this->access->authorize($actor, self::MODULE, ModuleCapability::View);
        [$start, $end] = $this->monthRange($month);
        $transactions = $this->visibleResources($actor, FinancialTransaction::class, 'financial_transactions', [ModuleCapability::View], FinancialTransaction::query())
            ->with('category')
            ->whereBetween('occurred_on', [$start, $end])
            ->orderBy('id')
            ->get();
        $totalsByCurrency = [];

        foreach ($transactions as $transaction) {
            $currency = $transaction->currency;
            $type = $transaction->type;
            $totalsByCurrency[$currency][$type][] = (string) $transaction->amount;
        }

        ksort($totalsByCurrency);
        $totals = [];

        foreach ($totalsByCurrency as $currency => $values) {
            $income = FinanceDecimalValue::sum($values['income'] ?? []);
            $expenses = FinanceDecimalValue::sum($values['expense'] ?? []);
            $totals[] = [
                'currency' => (string) $currency,
                'income' => $income,
                'expenses' => $expenses,
                'net' => FinanceDecimalValue::subtract($income, $expenses),
            ];
        }

        $budgetRows = $this->visibleResources($actor, FinanceBudget::class, 'finance_budgets', [ModuleCapability::View], FinanceBudget::query())
            ->with('category')
            ->whereDate('period_start', $start)
            ->orderBy('currency')->orderBy('finance_category_id')->orderBy('id')
            ->get();
        $budgets = $budgetRows->map(function (FinanceBudget $budget) use ($actor, $transactions): array {
            $spend = $transactions
                ->filter(fn (FinancialTransaction $transaction): bool => $transaction->type === 'expense'
                    && (int) $transaction->finance_category_id === (int) $budget->finance_category_id
                    && $transaction->currency === $budget->currency
                    && ($budget->is_private || ! $transaction->is_private))
                ->pluck('amount');
            $spent = FinanceDecimalValue::sum($spend);

            return $this->presentBudget($actor, $budget, $spent, ModuleCapability::View);
        })->all();

        return ['month' => $month, 'totals' => $totals, 'budgets' => $budgets];
    }

    private function findCategoryForUse(
        User $actor,
        int $categoryId,
        string $type,
        bool $recordIsPrivate,
        ModuleCapability $capability,
    ): FinanceCategory {
        $category = $this->visibleResources(
            $actor,
            FinanceCategory::class,
            'finance_categories',
            [$capability],
            FinanceCategory::query(),
        )->whereKey($categoryId)->lockForUpdate()->firstOrFail();
        $this->access->authorize($actor, self::MODULE, $capability, $category);

        if ($category->type !== $type) {
            throw ValidationException::withMessages(['finance_category_id' => 'Choose a category for this income or expense type.']);
        }

        if (! $recordIsPrivate && $category->is_private) {
            throw ValidationException::withMessages(['finance_category_id' => 'Shared financial records require a household category.']);
        }

        return $category;
    }

    /** @return list<array<string, mixed>> */
    private function visibleCategories(User $actor, ModuleCapability $capability): array
    {
        return $this->visibleResources($actor, FinanceCategory::class, 'finance_categories', [$capability], FinanceCategory::query())
            ->orderBy('type')->orderBy('name')->orderBy('id')
            ->get()->map(fn (FinanceCategory $category): array => $this->presentCategory($category))->all();
    }

    private function assertBudgetAvailable(
        User $actor,
        FinanceCategory $category,
        bool $isPrivate,
        string $periodStart,
        string $currency,
        ?int $exceptId = null,
    ): void {
        $query = FinanceBudget::query()
            ->where('finance_category_id', $category->id)
            ->where('period_start', $periodStart)
            ->where('currency', $currency)
            ->where('is_private', $isPrivate);
        $query->when($isPrivate, fn (Builder $builder) => $builder->where('owner_user_id', $actor->id));

        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages(['finance_category_id' => 'A budget already exists for this category, month and currency.']);
        }
    }

    private function assertCategoryNameAvailable(User $actor, string $type, string $name, bool $isPrivate, ?int $exceptId = null): void
    {
        $query = FinanceCategory::query()->where('type', $type)->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))]);
        $query->when($isPrivate,
            fn (Builder $builder) => $builder->where('is_private', true)->where('owner_user_id', $actor->id),
            fn (Builder $builder) => $builder->where('is_private', false));

        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages(['name' => 'A category with this name already exists in this visibility scope.']);
        }
    }

    private function authorizeSharedManagement(User $actor): void
    {
        $activeAdmin = User::query()
            ->whereKey($actor->id)
            ->where('is_admin', true)
            ->where('is_active', true)
            ->lockForUpdate()
            ->first();

        if (! $activeAdmin instanceof User) {
            throw new AuthorizationException('Only an active household administrator can create or manage shared financial records.');
        }

        $this->access->authorize($activeAdmin, self::MODULE, ModuleCapability::Manage);
    }

    private function authorizeMutation(User $actor, ModuleCapability $capability, Model $resource): void
    {
        if ($capability === ModuleCapability::Manage) {
            $this->authorizeSharedManagement($actor);
        }

        $this->access->authorize($actor, self::MODULE, $capability, $resource);
    }

    private function mutationCapability(Model&ModuleResource $resource): ModuleCapability
    {
        return $resource->isPrivate() ? ModuleCapability::Edit : ModuleCapability::Manage;
    }

    /** @param class-string<Model> $modelClass
     * @param  list<ModuleCapability>  $capabilities
     */
    private function findForMutation(User $actor, string $modelClass, string $table, int $id): Model&ModuleResource
    {
        $capabilities = [ModuleCapability::Edit, ModuleCapability::Manage];
        $resource = $this->visibleResources($actor, $modelClass, $table, $capabilities, (new $modelClass)->newQuery())
            ->whereKey($id)->lockForUpdate()->firstOrFail();

        if (! $resource instanceof ModuleResource) {
            throw new AuthorizationException('The financial record is unavailable.');
        }

        return $resource;
    }

    /** @param class-string<Model> $modelClass
     * @param  list<ModuleCapability>  $capabilities
     */
    private function visibleResources(User $actor, string $modelClass, string $table, array $capabilities, Builder $query): Builder
    {
        $morphType = (new $modelClass)->getMorphClass();
        $grantedCapabilities = array_map(fn (ModuleCapability $capability): string => $capability->value, $capabilities);

        return $query->where(function (Builder $visible) use ($actor, $grantedCapabilities, $morphType, $table): void {
            $visible->where('is_private', false)
                ->orWhere('owner_user_id', $actor->id)
                ->orWhereExists(function (QueryBuilder $permissions) use ($actor, $grantedCapabilities, $morphType, $table): void {
                    $permissions->selectRaw('1')
                        ->from('resource_user_permissions')
                        ->whereColumn('resource_user_permissions.resource_id', $table.'.id')
                        ->where('resource_user_permissions.resource_type', $morphType)
                        ->where('resource_user_permissions.user_id', $actor->id)
                        ->whereIn('resource_user_permissions.capability', $grantedCapabilities);
                });
        });
    }

    private function validatedAmount(mixed $value): string
    {
        return FinanceDecimalValue::money($value)
            ?? throw ValidationException::withMessages(['amount' => 'Enter a positive amount with up to 16 whole and 2 fractional digits.']);
    }

    private function periodStart(string $date): string
    {
        if (substr($date, 8, 2) !== '01') {
            throw ValidationException::withMessages(['period_start' => 'Choose the first day of the budget month.']);
        }

        return $date;
    }

    /** @return array{string, string} */
    private function monthRange(string $month): array
    {
        if (preg_match('/\A\d{4}-(0[1-9]|1[0-2])\z/', $month) !== 1) {
            throw ValidationException::withMessages(['month' => 'Choose a month in YYYY-MM format.']);
        }

        $start = CarbonImmutable::createFromFormat('!Y-m', $month);

        return [$start->format('Y-m-d'), $start->endOfMonth()->format('Y-m-d')];
    }

    private function assertRecurringDates(FinanceRecurringEntry $entry): void
    {
        $startsOn = $entry->starts_on?->format('Y-m-d');
        $nextDueOn = $entry->next_due_on?->format('Y-m-d');
        $endsOn = $entry->ends_on?->format('Y-m-d');

        if ($nextDueOn !== null && $nextDueOn < ($startsOn ?? '')) {
            throw ValidationException::withMessages(['next_due_on' => 'The next due date cannot precede the schedule start.']);
        }

        if ($endsOn !== null && ($endsOn < ($startsOn ?? '') || ($nextDueOn !== null && $nextDueOn > $endsOn))) {
            throw ValidationException::withMessages(['ends_on' => 'The final date must include the next due date and cannot precede the start.']);
        }

        if ($entry->is_active && $nextDueOn === null) {
            throw ValidationException::withMessages(['next_due_on' => 'An active schedule needs a next due date.']);
        }
    }

    private function nextOccurrence(CarbonImmutable $dueOn, string $frequency, int $anchorDay): CarbonImmutable
    {
        return match ($frequency) {
            'weekly' => $dueOn->addWeek(),
            'monthly' => $this->nextMonthOccurrence($dueOn, 1, $anchorDay),
            'quarterly' => $this->nextMonthOccurrence($dueOn, 3, $anchorDay),
            'yearly' => $this->nextMonthOccurrence($dueOn, 12, $anchorDay),
            default => throw ValidationException::withMessages(['frequency' => 'Choose a supported recurrence frequency.']),
        };
    }

    private function nextMonthOccurrence(CarbonImmutable $dueOn, int $months, int $anchorDay): CarbonImmutable
    {
        $nextMonth = $dueOn->startOfMonth()->addMonthsNoOverflow($months);

        return $nextMonth->day(min($anchorDay, $nextMonth->daysInMonth));
    }

    private function normalizedPayee(mixed $payee): ?string
    {
        if (! is_string($payee)) {
            return null;
        }

        $payee = trim($payee);

        return $payee === '' ? null : $payee;
    }

    /** @return array<string, mixed> */
    private function presentCategory(FinanceCategory $category): array
    {
        return [
            'id' => $category->id,
            'type' => $category->type,
            'name' => $category->name,
            'visibility' => $category->is_private ? 'private' : 'household',
            'created_at' => $category->created_at?->toISOString(),
            'updated_at' => $category->updated_at?->toISOString(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function presentRelatedCategory(
        User $actor,
        ?FinanceCategory $category,
        ModuleCapability $capability,
    ): ?array {
        if (! $category instanceof FinanceCategory) {
            return null;
        }

        try {
            $this->access->authorize($actor, self::MODULE, $capability, $category);
        } catch (AuthorizationException) {
            return null;
        }

        return $this->presentCategory($category);
    }

    /** @return array<string, mixed> */
    private function presentTransaction(
        User $actor,
        FinancialTransaction $transaction,
        ModuleCapability $capability,
    ): array {
        $category = $this->presentRelatedCategory($actor, $transaction->category, $capability);

        return [
            'id' => $transaction->id,
            'category' => $category,
            'finance_category_id' => $category === null ? null : (int) $transaction->finance_category_id,
            'type' => $transaction->type,
            'description' => $transaction->description,
            'payee' => $transaction->payee,
            'amount' => (string) $transaction->amount,
            'currency' => $transaction->currency,
            'occurred_on' => $transaction->occurred_on->format('Y-m-d'),
            'visibility' => $transaction->is_private ? 'private' : 'household',
            'recurring_entry_id' => $transaction->finance_recurring_entry_id === null ? null : (int) $transaction->finance_recurring_entry_id,
            'recurring_occurrence_on' => $transaction->recurring_occurrence_on?->format('Y-m-d'),
            'created_at' => $transaction->created_at?->toISOString(),
            'updated_at' => $transaction->updated_at?->toISOString(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentRecurringEntry(
        User $actor,
        FinanceRecurringEntry $entry,
        ModuleCapability $capability,
    ): array {
        $category = $this->presentRelatedCategory($actor, $entry->category, $capability);

        return [
            'id' => $entry->id,
            'category' => $category,
            'finance_category_id' => $category === null ? null : (int) $entry->finance_category_id,
            'type' => $entry->type,
            'description' => $entry->description,
            'amount' => (string) $entry->amount,
            'currency' => $entry->currency,
            'frequency' => $entry->frequency,
            'starts_on' => $entry->starts_on->format('Y-m-d'),
            'next_due_on' => $entry->next_due_on?->format('Y-m-d'),
            'ends_on' => $entry->ends_on?->format('Y-m-d'),
            'is_active' => (bool) $entry->is_active,
            'visibility' => $entry->is_private ? 'private' : 'household',
        ];
    }

    /** @return array<string, mixed> */
    private function presentBudget(
        User $actor,
        FinanceBudget $budget,
        ?string $spent,
        ModuleCapability $capability,
    ): array {
        $category = $this->presentRelatedCategory($actor, $budget->category, $capability);

        return [
            'id' => $budget->id,
            'category' => $category,
            'finance_category_id' => $category === null ? null : (int) $budget->finance_category_id,
            'amount' => (string) $budget->amount,
            'spent' => $spent,
            'remaining' => $spent === null ? null : FinanceDecimalValue::subtract((string) $budget->amount, $spent),
            'currency' => $budget->currency,
            'period_start' => $budget->period_start->format('Y-m-d'),
            'visibility' => $budget->is_private ? 'private' : 'household',
        ];
    }
}
