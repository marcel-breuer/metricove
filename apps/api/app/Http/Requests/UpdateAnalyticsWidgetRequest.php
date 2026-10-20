<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAnalyticsWidgetRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('title'))) {
            $this->merge(['title' => trim($this->input('title'))]);
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
            'title' => ['required', 'string', 'max:80'],
            'range_key' => ['required', Rule::in(['30d', '90d', '12m'])],
            'chart_type' => ['required', Rule::in(['line', 'bar'])],
        ];
    }
}
