<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'is_admin' => ['sometimes', 'required', 'boolean'],
            'is_active' => ['sometimes', 'required', 'boolean'],
            'name' => ['prohibited'],
            'email' => ['prohibited'],
            'password' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $this->exists('is_admin') && ! $this->exists('is_active')) {
                $validator->errors()->add('is_admin', 'Choose an administrator role or account status to update.');
            }
        }];
    }
}
