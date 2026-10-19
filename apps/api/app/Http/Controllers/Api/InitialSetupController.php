<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\SetupAlreadyCompleted;
use App\Http\Controllers\Controller;
use App\Http\Requests\CompleteInitialSetupRequest;
use App\Models\HouseholdSettings;
use App\Models\User;
use App\Services\Setup\CompleteInitialSetup;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class InitialSetupController extends Controller
{
    public function status(): JsonResponse
    {
        $settings = HouseholdSettings::query()->whereKey(1)->firstOrFail();

        return response()->json([
            'setup_required' => $settings->setup_completed_at === null && ! User::query()->exists(),
        ]);
    }

    public function complete(
        CompleteInitialSetupRequest $request,
        CompleteInitialSetup $completeInitialSetup,
    ): JsonResponse {
        try {
            $user = $completeInitialSetup->complete($request->validated());
        } catch (SetupAlreadyCompleted) {
            return response()->json([
                'message' => 'Initial setup has already been completed.',
            ], 409);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_admin' => $user->is_admin,
                'is_active' => $user->is_active,
            ],
        ], 201);
    }
}
