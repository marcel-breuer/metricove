<?php

namespace App\Http\Requests;

use App\Services\Utilities\DecimalValue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateUtilityMeterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'alert_threshold' => ['sometimes', 'nullable'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->all() === []) {
                $validator->errors()->add('meter', 'Provide a name or alert threshold to update.');
            }

            if ($this->exists('alert_threshold') && $this->input('alert_threshold') !== null
                && DecimalValue::normalize($this->input('alert_threshold')) === null) {
                $validator->errors()->add('alert_threshold', 'Enter a non-negative decimal with up to 14 whole and 6 fractional digits.');
            }
        });
    }
}
