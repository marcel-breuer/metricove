<?php

namespace App\Http\Requests;

use App\Services\Utilities\DecimalValue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreUtilityMeterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'meter_type' => ['required', 'in:electricity,gas,water'],
            'visibility' => ['required', 'in:private,household'],
            'description' => ['nullable', 'string', 'max:2000'],
            'alert_threshold' => ['nullable'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('alert_threshold') !== null
                && DecimalValue::normalize($this->input('alert_threshold')) === null) {
                $validator->errors()->add('alert_threshold', 'Enter a non-negative decimal with up to 14 whole and 6 fractional digits.');
            }
        });
    }
}
