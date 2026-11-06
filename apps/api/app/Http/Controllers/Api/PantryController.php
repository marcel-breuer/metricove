<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdjustPantryStockRequest;
use App\Http\Requests\StorePantryItemRequest;
use App\Http\Requests\UpdatePantryItemRequest;
use App\Models\User;
use App\Services\Pantry\PantryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PantryController extends Controller
{
    public function index(Request $request, PantryService $pantry): JsonResponse
    {
        $validated = $request->validate(['expiry' => ['sometimes', 'in:expired,today,soon,later,none']]);

        return response()->json(['items' => $pantry->index($this->actor($request), $validated['expiry'] ?? null)]);
    }

    public function store(StorePantryItemRequest $request, PantryService $pantry): JsonResponse
    {
        return response()->json([
            'message' => 'Pantry item added.',
            'item' => $pantry->create($this->actor($request), $request->validated()),
        ], 201);
    }

    public function update(UpdatePantryItemRequest $request, int $item, PantryService $pantry): JsonResponse
    {
        return response()->json([
            'message' => 'Pantry item updated.',
            'item' => $pantry->update($this->actor($request), $item, $request->validated()),
        ]);
    }

    public function adjust(AdjustPantryStockRequest $request, int $item, PantryService $pantry): JsonResponse
    {
        return response()->json([
            'message' => 'Stock adjustment recorded.',
            ...$pantry->adjust($this->actor($request), $item, $request->validated()),
        ]);
    }

    public function movements(Request $request, int $item, PantryService $pantry): JsonResponse
    {
        return response()->json(['movements' => $pantry->movements($this->actor($request), $item)]);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user('web');
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
