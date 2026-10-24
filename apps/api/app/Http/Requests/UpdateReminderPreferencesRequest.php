<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateReminderPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email_enabled' => ['sometimes', 'boolean'],
            'lead_days' => ['sometimes', 'integer', 'between:0,30'],
            'sources' => ['sometimes', 'array:finance,vehicles,utilities,pantry'],
            'sources.finance' => ['sometimes', 'boolean'],
            'sources.vehicles' => ['sometimes', 'boolean'],
            'sources.utilities' => ['sometimes', 'boolean'],
            'sources.pantry' => ['sometimes', 'boolean'],
        ];
    }
}
