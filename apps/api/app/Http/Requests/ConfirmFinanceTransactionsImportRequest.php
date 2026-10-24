<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmFinanceTransactionsImportRequest extends FormRequest
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
            'file' => ['required', 'file', 'max:10240'],
            'preview_token' => ['required', 'string', 'max:16384'],
            'confirmed' => ['required', 'accepted'],
            'idempotency_key' => ['required', 'uuid'],
            'mapping' => ['sometimes', 'required', 'array:occurred_on,description,amount,currency,payee,category'],
            'mapping.occurred_on' => ['required_with:mapping', 'integer', 'min:0', 'max:99'],
            'mapping.description' => ['required_with:mapping', 'integer', 'min:0', 'max:99'],
            'mapping.amount' => ['required_with:mapping', 'integer', 'min:0', 'max:99'],
            'mapping.currency' => ['required_with:mapping', 'integer', 'min:0', 'max:99'],
            'mapping.payee' => ['nullable', 'integer', 'min:0', 'max:99'],
            'mapping.category' => ['nullable', 'integer', 'min:0', 'max:99'],
            'category_defaults' => ['sometimes', 'array:expense,income'],
            'category_defaults.expense' => ['nullable', 'integer', 'min:1'],
            'category_defaults.income' => ['nullable', 'integer', 'min:1'],
            'selected_rows' => ['sometimes', 'array', 'max:1000'],
            'selected_rows.*' => ['integer', 'min:1', 'max:1001'],
            'selection_submitted' => ['sometimes', 'boolean'],
        ];
    }
}
