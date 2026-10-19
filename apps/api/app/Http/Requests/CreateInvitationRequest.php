<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class CreateInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user('web');

        return $user instanceof User
            && User::query()->whereKey($user->id)->where('is_active', true)->where('is_admin', true)->exists();
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        $this->merge([
            'email' => is_string($email) ? Str::lower(trim($email)) : $email,
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'name' => ['prohibited'],
            'password' => ['prohibited'],
            'password_confirmation' => ['prohibited'],
        ];
    }
}
