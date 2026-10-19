<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class UpdateMemberModulePermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user('web');

        return $user instanceof User
            && User::query()->whereKey($user->id)->where('is_active', true)->where('is_admin', true)->exists();
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'capabilities' => ['required', 'array:view,create,edit,manage'],
            'capabilities.view' => ['required', 'boolean'],
            'capabilities.create' => ['required', 'boolean'],
            'capabilities.edit' => ['required', 'boolean'],
            'capabilities.manage' => ['required', 'boolean'],
        ];
    }
}
