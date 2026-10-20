<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdatePantryItemRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['name', 'location'] as $field) {
            if (is_string($this->input($field))) {
                $value = trim($this->input($field));
                $this->merge([$field => $field === 'location' && $value === '' ? null : $value]);
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
            'location' => ['sometimes', 'nullable', 'string', 'max:120'],
            'best_before_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->hasAny(['name', 'location', 'best_before_on'])) {
                $validator->errors()->add('item', 'Change at least one pantry detail.');
            }
        });
    }
}
