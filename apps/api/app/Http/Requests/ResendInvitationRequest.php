<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class ResendInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user('web');

        return $user instanceof User
            && User::query()->whereKey($user->id)->where('is_active', true)->where('is_admin', true)->exists();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}
