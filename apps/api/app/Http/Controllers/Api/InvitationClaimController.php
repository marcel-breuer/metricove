<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ClaimInvitationRequest;
use App\Services\Auth\InvitationService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class InvitationClaimController extends Controller
{
    public function __invoke(ClaimInvitationRequest $request, InvitationService $invitations): JsonResponse
    {
        try {
            $user = $invitations->claim($request->validated());
        } catch (UniqueConstraintViolationException) {
            $user = null;
        }

        if ($user === null) {
            return response()->json([
                'message' => 'This invitation link is invalid, expired, already used, or cannot be claimed.',
            ], 422);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return response()->json([
            'message' => 'Your account is ready. You are signed in.',
        ]);
    }
}
