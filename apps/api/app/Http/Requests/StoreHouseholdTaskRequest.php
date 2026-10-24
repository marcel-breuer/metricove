<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreHouseholdTaskRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['title', 'description', 'timezone'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
        $this->merge([
            'visibility' => $this->input('visibility', 'private'),
            'priority' => $this->input('priority', 'medium'),
            'timezone' => $this->input('timezone', 'UTC'),
            'recurrence_interval' => $this->input('recurrence_interval', 1),
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:4000'],
            'due_at' => ['nullable', 'date'],
            'timezone' => ['required', 'timezone'],
            'priority' => ['required', 'in:low,medium,high'],
            'assignee_id' => ['nullable', 'integer', 'min:1'],
            'visibility' => ['required', 'in:private,household'],
            'recurrence_frequency' => ['nullable', 'in:daily,weekly,monthly,yearly'],
            'recurrence_interval' => ['required', 'integer', 'min:1', 'max:99'],
        ];
    }
}
