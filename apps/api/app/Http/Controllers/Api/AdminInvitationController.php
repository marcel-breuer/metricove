<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InvitationUnavailable;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateInvitationRequest;
use App\Http\Requests\ResendInvitationRequest;
use App\Http\Requests\RevokeInvitationRequest;
use App\Models\Invitation;
use App\Services\Auth\InvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class AdminInvitationController extends Controller
{
    public function index(Request $request, InvitationService $invitations): JsonResponse
    {
        return response()->json([
            'invitations' => $invitations->pending($request->user('web'))->map(fn (Invitation $invitation): array => $this->resource($invitation)),
        ]);
    }

    public function store(CreateInvitationRequest $request, InvitationService $invitations): JsonResponse
    {
        try {
            $invitation = $invitations->invite($request->validated('email'), $request->user('web'));
        } catch (InvitationUnavailable) {
            return response()->json(['message' => 'This address cannot receive an invitation.'], 422);
        } catch (TransportExceptionInterface $exception) {
            $this->logDeliveryFailure($exception);

            return response()->json([
                'message' => 'The invitation email could not be sent. The pending invitation can be resent.',
            ], 503);
        }

        return response()->json([
            'message' => 'Invitation email sent.',
            'invitation' => $this->resource($invitation),
        ], 201);
    }

    public function resend(
        ResendInvitationRequest $request,
        int $invitation,
        InvitationService $invitations,
    ): JsonResponse {
        try {
            $updated = $invitations->resend($invitation, $request->user('web'));
        } catch (InvitationUnavailable) {
            return response()->json(['message' => 'This invitation is no longer available.'], 404);
        } catch (TransportExceptionInterface $exception) {
            $this->logDeliveryFailure($exception);

            return response()->json([
                'message' => 'The invitation email could not be sent. The pending invitation can be resent.',
            ], 503);
        }

        return response()->json([
            'message' => 'Invitation email sent again.',
            'invitation' => $this->resource($updated),
        ]);
    }

    public function destroy(
        RevokeInvitationRequest $request,
        int $invitation,
        InvitationService $invitations,
    ): JsonResponse {
        if (! $invitations->revoke($invitation, $request->user('web'))) {
            return response()->json(['message' => 'This invitation is no longer available.'], 404);
        }

        return response()->json(['message' => 'Invitation revoked.']);
    }

    /** @return array{id: int, email: string, created_at: string, updated_at: string, expires_at: string, is_expired: bool} */
    private function resource(Invitation $invitation): array
    {
        return [
            'id' => $invitation->id,
            'email' => $invitation->email,
            'created_at' => $invitation->created_at->toIso8601String(),
            'updated_at' => $invitation->updated_at->toIso8601String(),
            'expires_at' => $invitation->expires_at->toIso8601String(),
            'is_expired' => $invitation->expires_at->isPast(),
        ];
    }

    private function logDeliveryFailure(TransportExceptionInterface $exception): void
    {
        Log::warning('Invitation email delivery failed.', [
            'exception_type' => $exception::class,
        ]);
    }
}
