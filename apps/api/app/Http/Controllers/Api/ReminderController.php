<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateReminderPreferencesRequest;
use App\Http\Requests\UpdateReminderRequest;
use App\Models\User;
use App\Services\Reminders\ReminderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReminderController extends Controller
{
    public function index(Request $request, ReminderService $reminders): JsonResponse
    {
        return response()->json($reminders->index($this->actor($request)));
    }

    public function preferences(Request $request, ReminderService $reminders): JsonResponse
    {
        return response()->json(['preferences' => $reminders->preferences($this->actor($request))]);
    }

    public function updatePreferences(UpdateReminderPreferencesRequest $request, ReminderService $reminders): JsonResponse
    {
        return response()->json([
            'message' => 'Reminder preferences updated.',
            'preferences' => $reminders->updatePreferences($this->actor($request), $request->validated()),
        ]);
    }

    public function update(UpdateReminderRequest $request, int $reminder, ReminderService $reminders): JsonResponse
    {
        $reminders->update($this->actor($request), $reminder, $request->validated('action'));

        return response()->json(['message' => 'Reminder updated.']);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user('web');
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
