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
            'target_type' => ['required', 'in:finance,vehicle'],
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
        });
    }
}
