<?php

namespace App\Http\Requests;

use App\Services\Finance\FinanceDecimalValue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreFinancialTransactionRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('currency'))) {
            $this->merge(['currency' => strtoupper(trim($this->input('currency')))]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'finance_category_id' => ['required', 'integer', 'min:1'],
            'type' => ['required', 'in:income,expense'],
            'description' => ['required', 'string', 'max:200'],
            'payee' => ['nullable', 'string', 'max:200'],
            'amount' => ['required'],
            'currency' => ['required', 'string', 'regex:/\A[A-Z]{3}\z/'],
            'occurred_on' => ['required', 'date_format:Y-m-d'],
            'visibility' => ['required', 'in:private,household'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (FinanceDecimalValue::money($this->input('amount')) === null) {
                $validator->errors()->add('amount', 'Enter a positive amount with up to 16 whole and 2 fractional digits.');
            }
        });
    }
}
