<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AskAssistantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'min:1', 'max:4000'],
            'allow_external_data_sharing' => ['sometimes', 'boolean'],
            'project_key' => ['nullable', 'string', 'max:120'],
        ];
    }
}
