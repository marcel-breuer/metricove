<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTaskChecklistItemRequest extends FormRequest
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

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:180'],
            'due_at' => ['sometimes', 'nullable', 'date'],
            'reminder_lead_minutes' => ['sometimes', 'integer', 'min:0', 'max:43200'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ];
    }
}
