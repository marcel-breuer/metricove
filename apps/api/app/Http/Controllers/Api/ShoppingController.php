<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ImportMealSuggestionsRequest;
use App\Http\Requests\MoveShoppingItemRequest;
use App\Http\Requests\PreviewMealSuggestionsRequest;
use App\Http\Requests\SetShoppingItemCheckedRequest;
use App\Http\Requests\StoreShoppingItemRequest;
use App\Http\Requests\StoreShoppingListRequest;
use App\Http\Requests\UpdateShoppingItemRequest;
use App\Http\Requests\UpdateShoppingListRequest;
use App\Models\User;
use App\Services\Shopping\MealShoppingSuggestionService;
use App\Services\Shopping\ShoppingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShoppingController extends Controller
{
    public function index(Request $request, ShoppingService $shopping): JsonResponse
    {
        return response()->json(['lists' => $shopping->index($this->actor($request))]);
    }

    public function show(Request $request, int $list, ShoppingService $shopping): JsonResponse
    {
        return response()->json($shopping->show($this->actor($request), $list));
    }

    public function storeList(StoreShoppingListRequest $request, ShoppingService $shopping): JsonResponse
    {
        return response()->json([
            'message' => 'Shopping list created.',
            'list' => $shopping->createList($this->actor($request), $request->validated()),
        ], 201);
    }

    public function updateList(UpdateShoppingListRequest $request, int $list, ShoppingService $shopping): JsonResponse
    {
        return response()->json([
            'message' => 'Shopping list updated.',
            'list' => $shopping->updateList($this->actor($request), $list, $request->validated()),
        ]);
    }

    public function history(Request $request, int $list, ShoppingService $shopping): JsonResponse
    {
        return response()->json(['events' => $shopping->history($this->actor($request), $list)]);
    }

    public function mealSuggestions(
        PreviewMealSuggestionsRequest $request,
        int $list,
        MealShoppingSuggestionService $suggestions,
    ): JsonResponse {
        return response()->json([
            'preview' => $suggestions->preview($this->actor($request), $list, $request->validated()),
        ]);
    }

    public function importMealSuggestions(
        ImportMealSuggestionsRequest $request,
        int $list,
        MealShoppingSuggestionService $suggestions,
    ): JsonResponse {
        return response()->json([
            'message' => 'Selected meal suggestions were added to the shopping list.',
            'items' => $suggestions->import($this->actor($request), $list, $request->validated()),
        ]);
    }

    public function storeItem(StoreShoppingItemRequest $request, int $list, ShoppingService $shopping): JsonResponse
    {
        return response()->json([
            'message' => 'Item added to the shopping list.',
            'item' => $shopping->createItem($this->actor($request), $list, $request->validated()),
        ], 201);
    }

    public function updateItem(UpdateShoppingItemRequest $request, int $item, ShoppingService $shopping): JsonResponse
    {
        return response()->json([
            'message' => 'Shopping item updated.',
            'item' => $shopping->updateItem($this->actor($request), $item, $request->validated()),
        ]);
    }

    public function setChecked(SetShoppingItemCheckedRequest $request, int $item, ShoppingService $shopping): JsonResponse
    {
        return response()->json([
            'message' => $request->validated('checked') ? 'Item checked off.' : 'Item restored to the shopping list.',
            'item' => $shopping->setChecked($this->actor($request), $item, $request->validated()),
        ]);
    }

    public function moveItem(MoveShoppingItemRequest $request, int $item, ShoppingService $shopping): JsonResponse
    {
        $shopping->moveItem($this->actor($request), $item, $request->validated());

        return response()->json(['message' => 'Shopping item order updated.']);
    }

    public function deleteItem(Request $request, int $item, ShoppingService $shopping): JsonResponse
    {
        $shopping->deleteItem($this->actor($request), $item);

        return response()->json(['message' => 'Shopping item removed.']);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user('web');
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
