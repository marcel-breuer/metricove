<?php

namespace App\Http\Requests;

use App\Services\Vehicles\VehicleDecimalValue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
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
            'amount' => ['nullable'],
            'currency' => ['required', 'string', 'regex:/\A[A-Z]{3}\z/'],
            'period_start' => ['required', 'date_format:Y-m-d'],
            'period_end' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:period_start'],
            'period' => ['required', 'in:once,monthly,quarterly,yearly'],
            'description' => ['nullable', 'string', 'max:500'],
            'fuel_liters' => ['nullable', 'regex:/\A\d{1,11}(?:\.\d{1,3})?\z/'],
            'fuel_unit_price' => ['nullable', 'regex:/\A\d{1,10}(?:\.\d{1,4})?\z/'],
            'fuel_odometer_value' => ['nullable', 'regex:/\A\d{1,17}(?:\.\d{1,3})?\z/'],
            'fuel_full_tank' => ['sometimes', 'boolean'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $fuelFields = ['fuel_liters', 'fuel_unit_price', 'fuel_odometer_value', 'fuel_full_tank'];
            $hasFuelDetails = collect(array_slice($fuelFields, 0, 3))->contains(fn (string $field): bool => $this->input($field) !== null && $this->input($field) !== '')
                || filter_var($this->input('fuel_full_tank', false), FILTER_VALIDATE_BOOL);

            if ($this->input('category') !== 'fuel' && $hasFuelDetails) {
                $validator->errors()->add('category', 'Fuel details are only available for fuel costs.');
            }

            $amount = $this->input('amount');
            if ($this->input('category') === 'fuel') {
                try {
                    VehicleDecimalValue::calculateFuelValues($amount, $this->input('fuel_liters'), $this->input('fuel_unit_price'));
                } catch (ValidationException $exception) {
                    foreach ($exception->errors() as $field => $messages) {
                        foreach ($messages as $message) {
                            $validator->errors()->add($field, $message);
                        }
                    }
                }
            } elseif (VehicleDecimalValue::money($amount) === null) {
                $validator->errors()->add('amount', 'Enter a non-negative amount with up to 16 whole and 2 fractional digits.');
            }
        });
    }
}
