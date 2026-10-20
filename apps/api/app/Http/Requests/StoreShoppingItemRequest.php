<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreShoppingItemRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:180'],
            'quantity' => ['nullable', 'string', 'regex:/\A(?:0|[1-9]\d{0,10})(?:\.\d{1,3})?\z/'],
            'unit' => ['nullable', 'string', 'max:32'],
        ];
    }
}
