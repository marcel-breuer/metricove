<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportMealSuggestionsRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->input('subtract_pantry') === null) {
            $this->merge(['subtract_pantry' => false]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'week_start' => ['required', 'date_format:Y-m-d'],
            'subtract_pantry' => ['required', 'boolean'],
            'expected_list_version' => ['required', 'integer', 'min:1'],
            'preview_expires_at' => ['required', 'integer', 'min:1'],
            'preview_token' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'confirmed' => ['required', 'accepted'],
            'suggestion_ids' => ['required', 'array', 'min:1', 'max:100'],
            'suggestion_ids.*' => ['required', 'string', 'distinct', 'regex:/\A[a-f0-9]{64}\z/'],
        ];
    }
}
