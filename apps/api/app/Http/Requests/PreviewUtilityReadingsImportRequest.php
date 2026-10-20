<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PreviewUtilityReadingsImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
            'mapping' => ['sometimes', 'required', 'array:reading_date,value,note'],
            'mapping.reading_date' => ['required_with:mapping', 'integer', 'min:0', 'max:99'],
            'mapping.value' => ['required_with:mapping', 'integer', 'min:0', 'max:99'],
            'mapping.note' => ['nullable', 'integer', 'min:0', 'max:99'],
        ];
    }
}
