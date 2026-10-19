<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResendInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user('web');

        return $user !== null && $user->is_active && $user->is_admin;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}
