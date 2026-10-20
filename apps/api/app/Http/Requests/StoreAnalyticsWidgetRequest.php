<?php

namespace App\Http\Requests;

use App\Services\Analytics\AnalyticsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAnalyticsWidgetRequest extends FormRequest
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
            'metric_key' => ['required', 'string', Rule::in(array_keys(AnalyticsService::metrics()))],
            'title' => ['nullable', 'string', 'max:80'],
            'range_key' => ['required', Rule::in(['30d', '90d', '12m'])],
            'chart_type' => ['required', Rule::in(['line', 'bar'])],
        ];
    }
}
