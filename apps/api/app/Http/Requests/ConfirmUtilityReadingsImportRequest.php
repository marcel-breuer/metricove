<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmUtilityReadingsImportRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $key = $this->header('Idempotency-Key');
        $this->merge(['idempotency_key' => is_string($key) ? strtolower($key) : null]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
            'preview_token' => ['required', 'string', 'max:2048'],
            'confirmed' => ['required', 'accepted'],
            'idempotency_key' => ['required', 'uuid'],
            'mapping' => ['required', 'array:reading_date,value,note'],
            'mapping.reading_date' => ['required', 'integer', 'min:0', 'max:99'],
            'mapping.value' => ['required', 'integer', 'min:0', 'max:99'],
            'mapping.note' => ['nullable', 'integer', 'min:0', 'max:99'],
        ];
    }
}
