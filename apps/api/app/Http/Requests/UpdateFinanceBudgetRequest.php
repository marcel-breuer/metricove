<?php

namespace App\Http\Requests;

use App\Services\Finance\FinanceDecimalValue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateFinanceBudgetRequest extends FormRequest
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
            'finance_category_id' => ['sometimes', 'required', 'integer', 'min:1'],
            'amount' => ['sometimes', 'required'],
            'currency' => ['sometimes', 'required', 'string', 'regex:/\A[A-Z]{3}\z/'],
            'period_start' => ['sometimes', 'required', 'date_format:Y-m-d'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->has('amount') && FinanceDecimalValue::money($this->input('amount')) === null) {
                $validator->errors()->add('amount', 'Enter a positive amount with up to 16 whole and 2 fractional digits.');
            }

            if ($this->filled('period_start') && substr($this->input('period_start'), 8, 2) !== '01') {
                $validator->errors()->add('period_start', 'Choose the first day of the budget month.');
            }
        });
    }
}
