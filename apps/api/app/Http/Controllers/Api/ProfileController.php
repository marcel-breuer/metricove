<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $this->publicUser($request->user('web')),
        ]);
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user('web');

        try {
            $user->fill($request->validated())->save();
        } catch (UniqueConstraintViolationException) {
            return response()->json([
                'message' => 'The email address is already in use.',
                'errors' => [
                    'email' => ['The email address is already in use.'],
                ],
            ], 422);
        }

        return response()->json([
            'user' => $this->publicUser($user),
        ]);
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user('web');
        abort_unless($user instanceof User, 401);

        $user->forceFill(['password' => $request->validated('password')])->save();

        return response()->json(['message' => 'Your password has been changed.']);
    }

    /**
     * @return array{id: int, name: string, email: string, is_admin: bool, language: string, number_locale: string, default_currency: string, currency_display: string}
     */
    private function publicUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'is_admin' => $user->is_admin,
            'language' => $user->language,
            'number_locale' => $user->number_locale,
            'default_currency' => $user->default_currency,
            'currency_display' => $user->currency_display,
        ];
    }
}
