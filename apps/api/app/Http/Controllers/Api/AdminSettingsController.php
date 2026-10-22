<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateAiProviderSettingsRequest;
use App\Models\User;
use App\Services\AI\AiProviderConfiguration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminSettingsController extends Controller
{
    public function show(Request $request, AiProviderConfiguration $configuration): JsonResponse
    {
        return response()->json(['ai' => $configuration->forAdmin()]);
    }

    public function update(UpdateAiProviderSettingsRequest $request, AiProviderConfiguration $configuration): JsonResponse
    {
        $actor = $request->user('web');
        abort_unless($actor instanceof User, 401);

        return response()->json([
            'message' => 'General settings saved.',
            'ai' => $configuration->update($actor, $request->validated()),
        ]);
    }
}
