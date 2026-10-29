<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTaskChecklistItemRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('title'))) {
            $this->merge(['title' => trim($this->input('title'))]);
        }
        $this->merge(['reminder_lead_minutes' => $this->input('reminder_lead_minutes', 0)]);
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
            'due_at' => ['sometimes', 'nullable', 'date'],
            'reminder_lead_minutes' => ['required', 'integer', 'min:0', 'max:43200'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ];
    }
}
