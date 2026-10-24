<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTrackerEntryRequest;
use App\Http\Requests\StoreTrackerRequest;
use App\Http\Requests\UpdateTrackerSchemaRequest;
use App\Models\User;
use App\Services\Tracking\TrackerEntryService;
use App\Services\Tracking\TrackerSchemaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrackingController extends Controller
{
    public function index(Request $request, TrackerSchemaService $trackers): JsonResponse
    {
        return response()->json(['trackers' => $trackers->index($this->actor($request))]);
    }

    public function store(StoreTrackerRequest $request, TrackerSchemaService $trackers): JsonResponse
    {
        return response()->json([
            'message' => 'Tracker created.',
            'tracker' => $trackers->create($this->actor($request), $request->validated()),
        ], 201);
    }

    public function show(Request $request, int $tracker, TrackerSchemaService $trackers): JsonResponse
    {
        return response()->json($trackers->show($this->actor($request), $tracker));
    }

    public function update(
        UpdateTrackerSchemaRequest $request,
        int $tracker,
        TrackerSchemaService $trackers,
    ): JsonResponse {
        return response()->json([
            'message' => 'Tracker schema updated.',
            'tracker' => $trackers->update($this->actor($request), $tracker, $request->validated()),
        ]);
    }

    public function destroy(Request $request, int $tracker, TrackerSchemaService $trackers): JsonResponse
    {
        $trackers->delete($this->actor($request), $tracker);

        return response()->json(['message' => 'Tracker deleted.']);
    }

    public function entries(Request $request, int $tracker, TrackerEntryService $entries): JsonResponse
    {
        $filters = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'before_recorded_at' => ['required_with:before_id', 'date'],
            'before_id' => ['required_with:before_recorded_at', 'integer', 'min:1'],
        ]);

        return response()->json($entries->history(
            $this->actor($request),
            $tracker,
            (int) ($filters['limit'] ?? 30),
            isset($filters['before_recorded_at']) ? (string) $filters['before_recorded_at'] : null,
            isset($filters['before_id']) ? (int) $filters['before_id'] : null,
        ));
    }

    public function storeEntry(
        StoreTrackerEntryRequest $request,
        int $tracker,
        TrackerEntryService $entries,
    ): JsonResponse {
        return response()->json([
            'message' => 'Tracker entry saved.',
            'entry' => $entries->create($this->actor($request), $tracker, $request->validated()),
        ], 201);
    }

    public function updateEntry(
        StoreTrackerEntryRequest $request,
        int $entry,
        TrackerEntryService $entries,
    ): JsonResponse {
        return response()->json([
            'message' => 'Tracker entry updated.',
            'entry' => $entries->update($this->actor($request), $entry, $request->validated()),
        ]);
    }

    public function destroyEntry(Request $request, int $entry, TrackerEntryService $entries): JsonResponse
    {
        $entries->delete($this->actor($request), $entry);

        return response()->json(['message' => 'Tracker entry deleted.']);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user('web');

        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
