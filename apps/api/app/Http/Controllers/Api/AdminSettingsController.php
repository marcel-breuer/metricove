<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateAiProviderSettingsRequest;
use App\Http\Requests\UpdateNetworkAccessSettingsRequest;
use App\Models\User;
use App\Services\AI\AiProviderConfiguration;
use App\Services\NetworkAccess\ClientIpResolver;
use App\Services\NetworkAccess\NetworkAccessConfiguration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminSettingsController extends Controller
{
    public function show(
        Request $request,
        AiProviderConfiguration $configuration,
        NetworkAccessConfiguration $networkAccess,
        ClientIpResolver $clientIpResolver,
    ): JsonResponse {
        return response()->json([
            'ai' => $configuration->forAdmin(),
            'network_access' => $networkAccess->forAdmin($clientIpResolver->resolve($request)),
        ]);
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

    public function updateNetworkAccess(
        UpdateNetworkAccessSettingsRequest $request,
        NetworkAccessConfiguration $configuration,
        ClientIpResolver $clientIpResolver,
    ): JsonResponse {
        $actor = $request->user('web');
        abort_unless($actor instanceof User, 401);

        $validated = $request->validated();
        $clientIp = $clientIpResolver->resolve($request);
        if ($validated['allowed_ip_ranges'] !== []
            && ! $configuration->includesClientAddress($validated['allowed_ip_ranges'], $clientIp)
            && ! $request->boolean('confirm_lockout')) {
            return response()->json([
                'message' => 'The new allowlist does not include the current client address.',
                'reason' => 'network_access_lockout',
            ], 409);
        }

        $configuration->update($actor, $validated['allowed_ip_ranges']);

        return response()->json([
            'message' => 'Network access settings saved.',
            'network_access' => $configuration->forAdmin($clientIp),
        ]);
    }
}
