<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMealPlanSlotRequest;
use App\Http\Requests\UpdateMealPlanSlotRequest;
use App\Models\User;
use App\Services\MealPlanning\MealPlanningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MealPlanningController extends Controller
{
    public function week(Request $request, MealPlanningService $plans): JsonResponse
    {
        $validated = $request->validate(['week_start' => ['required', 'date_format:Y-m-d']]);

        return response()->json($plans->week($this->actor($request), $validated['week_start']));
    }

    public function store(StoreMealPlanSlotRequest $request, MealPlanningService $plans): JsonResponse
    {
        return response()->json([
            'message' => 'Meal planned.',
            'slot' => $plans->create($this->actor($request), $request->validated()),
        ], 201);
    }

    public function update(UpdateMealPlanSlotRequest $request, int $slot, MealPlanningService $plans): JsonResponse
    {
        return response()->json([
            'message' => 'Meal plan updated.',
            'slot' => $plans->update($this->actor($request), $slot, $request->validated()),
        ]);
    }

    public function destroy(Request $request, int $slot, MealPlanningService $plans): JsonResponse
    {
        $plans->delete($this->actor($request), $slot);

        return response()->json(['message' => 'Meal removed from the plan.']);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user('web');

        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
