<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReceiptIndexRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'search' => is_string($this->input('search')) ? trim($this->input('search')) : $this->input('search'),
            'currency' => is_string($this->input('currency')) ? strtoupper(trim($this->input('currency'))) : $this->input('currency'),
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'date_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'min_amount' => ['sometimes', 'nullable', 'regex:/\A\d{1,16}(?:\.\d{1,2})?\z/'],
            'max_amount' => ['sometimes', 'nullable', 'regex:/\A\d{1,16}(?:\.\d{1,2})?\z/'],
            'currency' => ['required_with:min_amount,max_amount', 'nullable', 'regex:/\A[A-Z]{3}\z/'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
