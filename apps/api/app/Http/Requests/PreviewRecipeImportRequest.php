<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PreviewRecipeImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'source_url' => ['nullable', 'string', 'max:2048', 'url'],
            'file' => ['nullable', 'file', 'mimes:pdf', 'mimetypes:application/pdf', 'max:10240'],
            'visibility' => ['required', 'in:private,household'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $hasUrl = filled($this->input('source_url'));
            $hasFile = $this->hasFile('file');

            if ($hasUrl === $hasFile) {
                $validator->errors()->add('source', 'Provide either a recipe URL or one PDF file.');
            }
        });
    }
}
