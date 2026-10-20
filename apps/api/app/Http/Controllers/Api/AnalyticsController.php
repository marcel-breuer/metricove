<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAnalyticsWidgetRequest;
use App\Http\Requests\UpdateAnalyticsWidgetRequest;
use App\Models\User;
use App\Services\Analytics\AnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    public function catalog(Request $request, AnalyticsService $analytics): JsonResponse
    {
        return response()->json(['metrics' => $analytics->catalog($this->actor($request))]);
    }

    public function dashboard(Request $request, AnalyticsService $analytics): JsonResponse
    {
        return response()->json($analytics->dashboard($this->actor($request)));
    }

    public function store(StoreAnalyticsWidgetRequest $request, AnalyticsService $analytics): JsonResponse
    {
        return response()->json([
            'message' => 'Dashboard widget created.',
            'widget' => $analytics->createWidget($this->actor($request), $request->validated()),
        ], 201);
    }

    public function update(UpdateAnalyticsWidgetRequest $request, int $widget, AnalyticsService $analytics): JsonResponse
    {
        return response()->json([
            'message' => 'Dashboard widget updated.',
            'widget' => $analytics->updateWidget($this->actor($request), $widget, $request->validated()),
        ]);
    }

    public function destroy(Request $request, int $widget, AnalyticsService $analytics): JsonResponse
    {
        $analytics->deleteWidget($this->actor($request), $widget);

        return response()->json(['message' => 'Dashboard widget removed.']);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user('web');
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
