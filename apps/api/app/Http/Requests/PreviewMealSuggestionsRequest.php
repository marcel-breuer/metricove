<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PreviewMealSuggestionsRequest extends FormRequest
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
        ];
    }
}
