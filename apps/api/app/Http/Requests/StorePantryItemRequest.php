<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePantryItemRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['name', 'unit', 'location'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }

        if ($this->input('visibility') === null) {
            $this->merge(['visibility' => 'private']);
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
            'quantity' => ['required', 'string', 'regex:/\A(?:0|[1-9]\d{0,10})(?:\.\d{1,3})?\z/'],
            'unit' => ['required', 'string', 'max:32'],
            'location' => ['nullable', 'string', 'max:120'],
            'best_before_on' => ['nullable', 'date_format:Y-m-d'],
            'visibility' => ['required', 'in:private,household'],
        ];
    }
}
