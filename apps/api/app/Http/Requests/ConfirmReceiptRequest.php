<?php

namespace App\Http\Requests;

use App\Services\Finance\FinanceDecimalValue;
use App\Services\Vehicles\VehicleDecimalValue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;

class ConfirmReceiptRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'currency' => is_string($this->input('currency')) ? strtoupper(trim($this->input('currency'))) : $this->input('currency'),
            'idempotency_key' => $this->header('Idempotency-Key'),
        ]);
    }

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
            'preview_token' => ['required', 'string', 'max:10000'],
            'confirmed' => ['accepted'],
            'idempotency_key' => ['required', 'uuid'],
            'target_type' => ['required', 'in:archive,finance,vehicle'],
            'finance_category_id' => ['required_if:target_type,finance', 'nullable', 'integer', 'min:1'],
            'vehicle_id' => ['required_if:target_type,vehicle', 'nullable', 'integer', 'min:1'],
            'vehicle_category' => ['required_if:target_type,vehicle', 'nullable', 'in:fuel,maintenance,insurance,tax,registration,finance,other'],
            'description' => ['required', 'string', 'max:200'],
            'merchant' => ['nullable', 'string', 'max:200'],
            'invoice_number' => ['nullable', 'string', 'max:100'],
            'invoice_date' => ['required', 'date_format:Y-m-d'],
            'amount' => ['required'],
            'currency' => ['required', 'string', 'regex:/\A[A-Z]{3}\z/'],
            'tax_amount' => ['nullable'],
            'fuel_liters' => ['nullable', 'regex:/\A\d{1,11}(?:\.\d{1,3})?\z/'],
            'fuel_unit_price' => ['nullable', 'regex:/\A\d{1,10}(?:\.\d{1,4})?\z/'],
            'fuel_odometer_value' => ['nullable', 'regex:/\A\d{1,17}(?:\.\d{1,3})?\z/'],
            'reviewed_fields' => ['sometimes', 'array', 'max:9'],
            'reviewed_fields.*' => ['string', 'distinct', 'in:merchant,invoice_number,invoice_date,amount,currency,tax_amount,fuel_liters,fuel_unit_price,fuel_odometer_value'],
            'duplicate_acknowledged' => ['sometimes', 'boolean'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (FinanceDecimalValue::money($this->input('amount')) === null) {
                $validator->errors()->add('amount', 'Enter a non-negative amount with up to 16 whole and 2 fractional digits.');
            }

            $taxAmount = $this->input('tax_amount');
            if ($taxAmount !== null && $taxAmount !== '' && VehicleDecimalValue::money($taxAmount) === null) {
                $validator->errors()->add('tax_amount', 'Enter a non-negative tax amount with up to 16 whole and 2 fractional digits.');
            }

            $fuelLiters = $this->input('fuel_liters');
            $fuelUnitPrice = $this->input('fuel_unit_price');
            $fuelOdometerValue = $this->input('fuel_odometer_value');
            if (($fuelLiters === null || $fuelLiters === '') !== ($fuelUnitPrice === null || $fuelUnitPrice === '')) {
                $validator->errors()->add('fuel_liters', 'Enter both fuel quantity and unit price, or leave both blank.');
            }
            if ($fuelLiters !== null && $fuelLiters !== ''
                && (VehicleDecimalValue::fuelQuantity($fuelLiters) === null || preg_match('/\A0(?:\.0+)?\z/', (string) $fuelLiters) === 1)) {
                $validator->errors()->add('fuel_liters', 'Enter a positive fuel quantity with up to 11 whole and 3 fractional digits.');
            }
            if ($fuelUnitPrice !== null && $fuelUnitPrice !== ''
                && (VehicleDecimalValue::fuelUnitPrice($fuelUnitPrice) === null || preg_match('/\A0(?:\.0+)?\z/', (string) $fuelUnitPrice) === 1)) {
                $validator->errors()->add('fuel_unit_price', 'Enter a positive unit price with up to 10 whole and 4 fractional digits.');
            }
            if ($fuelOdometerValue !== null && $fuelOdometerValue !== '' && VehicleDecimalValue::distance($fuelOdometerValue) === null) {
                $validator->errors()->add('fuel_odometer_value', 'Enter a non-negative odometer value with up to 17 whole and 3 fractional digits.');
            }
        });
    }
}
