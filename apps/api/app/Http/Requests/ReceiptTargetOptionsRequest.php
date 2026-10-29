<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReceiptTargetOptionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'target_type' => ['required', 'in:finance,vehicle'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}
