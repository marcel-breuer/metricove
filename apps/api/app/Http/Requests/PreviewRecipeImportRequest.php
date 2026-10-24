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

    public function messages(): array
    {
        return [
            'source_url.string' => 'Enter a recipe link.',
            'source_url.max' => 'The recipe link must not exceed 2048 characters.',
            'source_url.url' => 'Enter a valid recipe page URL.',
            'file.file' => 'Upload a PDF file.',
            'file.mimes' => 'Upload a PDF file.',
            'file.mimetypes' => 'Upload a PDF file.',
            'file.max' => 'The PDF must be 10 MB or smaller.',
            'visibility.required' => 'Choose how to save the recipe.',
            'visibility.in' => 'Choose a valid recipe visibility.',
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
