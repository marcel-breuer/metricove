<?php

namespace App\Http\Requests;

use App\Services\Vehicles\VehicleDecimalValue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CorrectVehicleOdometerReadingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reading_date' => ['required', 'date_format:Y-m-d'],
            'value' => ['required'],
            'note' => ['present', 'nullable', 'string', 'max:2000'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (VehicleDecimalValue::distance($this->input('value')) === null) {
                $validator->errors()->add('value', 'Enter a non-negative distance with up to 17 whole and 3 fractional digits.');
            }
        });
    }
}
