<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateShoppingItemRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['name', 'quantity', 'unit'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'expected_version' => ['required', 'integer', 'min:1'],
            'name' => ['sometimes', 'required', 'string', 'max:180'],
            'quantity' => ['sometimes', 'nullable', 'string', 'regex:/\A(?:0|[1-9]\d{0,10})(?:\.\d{1,3})?\z/'],
            'unit' => ['sometimes', 'nullable', 'string', 'max:32'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:2147483647'],
        ];
    }
}
