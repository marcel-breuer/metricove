<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class PasswordResetService
{
    public function send(string $email): void
    {
        Password::broker()->sendResetLink(['email' => $email]);
    }

    /**
     * @param  array{email: string, token: string, password: string, password_confirmation: string}  $credentials
     */
    public function reset(array $credentials): string
    {
        return Password::broker()->reset($credentials, function (User $user, string $password): void {
            $user->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
            ])->save();

            event(new PasswordReset($user));
        });
    }
}
