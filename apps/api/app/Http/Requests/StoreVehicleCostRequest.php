<?php

namespace App\Http\Requests;

use App\Services\Vehicles\VehicleDecimalValue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreVehicleCostRequest extends FormRequest
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
            'category' => ['required', 'in:fuel,maintenance,insurance,tax,registration,finance,other'],
            'amount' => ['required'],
            'currency' => ['required', 'string', 'regex:/\A[A-Z]{3}\z/'],
            'period_start' => ['required', 'date_format:Y-m-d'],
            'period_end' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:period_start'],
            'period' => ['required', 'in:once,monthly,quarterly,yearly'],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (VehicleDecimalValue::money($this->input('amount')) === null) {
                $validator->errors()->add('amount', 'Enter a non-negative amount with up to 16 whole and 2 fractional digits.');
            }
        });
    }
}
