<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RecordFinanceRecurringEntryRequest;
use App\Http\Requests\StoreFinanceBudgetRequest;
use App\Http\Requests\StoreFinanceCategoryRequest;
use App\Http\Requests\StoreFinanceRecurringEntryRequest;
use App\Http\Requests\StoreFinancialTransactionRequest;
use App\Http\Requests\UpdateFinanceBudgetRequest;
use App\Http\Requests\UpdateFinanceCategoryRequest;
use App\Http\Requests\UpdateFinanceRecurringEntryRequest;
use App\Http\Requests\UpdateFinancialTransactionRequest;
use App\Models\User;
use App\Services\Finance\FinanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinanceController extends Controller
{
    public function categories(Request $request, FinanceService $finance): JsonResponse
    {
        return response()->json(['categories' => $finance->categories($this->actor($request))]);
    }

    public function storeCategory(StoreFinanceCategoryRequest $request, FinanceService $finance): JsonResponse
    {
        return response()->json([
            'message' => 'Category created.',
            'category' => $finance->createCategory($this->actor($request), $request->validated()),
        ], 201);
    }

    public function updateCategory(UpdateFinanceCategoryRequest $request, int $category, FinanceService $finance): JsonResponse
    {
        return response()->json([
            'message' => 'Category updated.',
            'category' => $finance->updateCategory($this->actor($request), $category, $request->validated()),
        ]);
    }

    public function deleteCategory(Request $request, int $category, FinanceService $finance): JsonResponse
    {
        $finance->deleteCategory($this->actor($request), $category);

        return response()->json(['message' => 'Category deleted.']);
    }

    public function transactions(Request $request, FinanceService $finance): JsonResponse
    {
        return response()->json(['transactions' => $finance->transactions($this->actor($request), $this->month($request))]);
    }

    public function storeTransaction(StoreFinancialTransactionRequest $request, FinanceService $finance): JsonResponse
    {
        return response()->json([
            'message' => 'Transaction created.',
            'transaction' => $finance->createTransaction($this->actor($request), $request->validated()),
        ], 201);
    }

    public function updateTransaction(UpdateFinancialTransactionRequest $request, int $transaction, FinanceService $finance): JsonResponse
    {
        return response()->json([
            'message' => 'Transaction updated.',
            'transaction' => $finance->updateTransaction($this->actor($request), $transaction, $request->validated()),
        ]);
    }

    public function deleteTransaction(Request $request, int $transaction, FinanceService $finance): JsonResponse
    {
        $finance->deleteTransaction($this->actor($request), $transaction);

        return response()->json(['message' => 'Transaction deleted.']);
    }

    public function recurringEntries(Request $request, FinanceService $finance): JsonResponse
    {
        return response()->json(['recurring_entries' => $finance->recurringEntries($this->actor($request))]);
    }

    public function storeRecurringEntry(StoreFinanceRecurringEntryRequest $request, FinanceService $finance): JsonResponse
    {
        return response()->json([
            'message' => 'Recurring entry created.',
            'recurring_entry' => $finance->createRecurringEntry($this->actor($request), $request->validated()),
        ], 201);
    }

    public function updateRecurringEntry(UpdateFinanceRecurringEntryRequest $request, int $entry, FinanceService $finance): JsonResponse
    {
        return response()->json([
            'message' => 'Recurring entry updated.',
            'recurring_entry' => $finance->updateRecurringEntry($this->actor($request), $entry, $request->validated()),
        ]);
    }

    public function deleteRecurringEntry(Request $request, int $entry, FinanceService $finance): JsonResponse
    {
        $finance->deleteRecurringEntry($this->actor($request), $entry);

        return response()->json(['message' => 'Recurring entry deleted.']);
    }

    public function recordRecurringOccurrence(RecordFinanceRecurringEntryRequest $request, int $entry, FinanceService $finance): JsonResponse
    {
        $result = $finance->recordRecurringOccurrence($this->actor($request), $entry, $request->validated());

        return response()->json([
            'message' => $result['created'] ? 'Recurring occurrence recorded.' : 'Recurring occurrence was already recorded.',
            'transaction' => $result['transaction'],
        ], $result['created'] ? 201 : 200);
    }

    public function budgets(Request $request, FinanceService $finance): JsonResponse
    {
        return response()->json(['budgets' => $finance->budgets($this->actor($request), $this->month($request))]);
    }

    public function overview(Request $request, FinanceService $finance): JsonResponse
    {
        return response()->json($finance->overview(
            $this->actor($request),
            $this->monthOrCurrent($request),
        ));
    }

    public function storeBudget(StoreFinanceBudgetRequest $request, FinanceService $finance): JsonResponse
    {
        return response()->json([
            'message' => 'Budget created.',
            'budget' => $finance->createBudget($this->actor($request), $request->validated()),
        ], 201);
    }

    public function updateBudget(UpdateFinanceBudgetRequest $request, int $budget, FinanceService $finance): JsonResponse
    {
        return response()->json([
            'message' => 'Budget updated.',
            'budget' => $finance->updateBudget($this->actor($request), $budget, $request->validated()),
        ]);
    }

    public function deleteBudget(Request $request, int $budget, FinanceService $finance): JsonResponse
    {
        $finance->deleteBudget($this->actor($request), $budget);

        return response()->json(['message' => 'Budget deleted.']);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user('web');

        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    private function month(Request $request): ?string
    {
        $validated = $request->validate(['month' => ['sometimes', 'nullable', 'date_format:Y-m']]);

        return $validated['month'] ?? null;
    }

    private function monthOrCurrent(Request $request): string
    {
        return $this->month($request) ?? now()->format('Y-m');
    }
}
