<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RequestPasswordResetRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Services\Auth\PasswordResetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class PasswordResetController extends Controller
{
    public function send(
        RequestPasswordResetRequest $request,
        PasswordResetService $passwordReset,
    ): JsonResponse {
        try {
            $passwordReset->send($request->validated('email'));
        } catch (TransportExceptionInterface $exception) {
            Log::warning('Password reset email delivery failed.', [
                'exception_type' => $exception::class,
            ]);
        }

        return response()->json([
            'message' => 'If an account exists for that address, password reset instructions have been sent.',
        ], 202);
    }

    public function reset(
        ResetPasswordRequest $request,
        PasswordResetService $passwordReset,
    ): JsonResponse {
        $status = $passwordReset->reset($request->validated());

        if ($status !== 'passwords.reset') {
            return response()->json([
                'message' => 'This password reset link is invalid or has expired.',
            ], 422);
        }

        return response()->json([
            'message' => 'Your password has been reset. You can sign in now.',
        ]);
    }
}
