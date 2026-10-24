<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateHouseholdTaskRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['title', 'description', 'timezone'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:180'],
            'description' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'due_at' => ['sometimes', 'nullable', 'date'],
            'timezone' => ['sometimes', 'required', 'timezone'],
            'priority' => ['sometimes', 'required', 'in:low,medium,high'],
            'assignee_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'recurrence_frequency' => ['sometimes', 'nullable', 'in:daily,weekly,monthly,yearly'],
            'recurrence_interval' => ['sometimes', 'required', 'integer', 'min:1', 'max:99'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ];
    }
}
