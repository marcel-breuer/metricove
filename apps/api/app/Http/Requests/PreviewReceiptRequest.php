<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class PreviewReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $file = $this->file('file');
        $fileRules = ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'];
        if ($file instanceof UploadedFile && $file->getMimeType() !== 'application/pdf') {
            $fileRules[] = 'image';
            $fileRules[] = 'dimensions:max_width=6000,max_height=6000';
        }

        return [
            'file' => $fileRules,
        ];
    }
}
