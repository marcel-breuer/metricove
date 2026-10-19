<?php

namespace App\Http\Requests;

use App\Services\Vehicles\VehicleDecimalValue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreVehicleMaintenanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:160'],
            'service_type' => ['required', 'in:service,repair,inspection,tires,registration,other'],
            'occurred_on' => ['required', 'date_format:Y-m-d'],
            'odometer_value' => ['nullable'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('odometer_value') !== null && $this->input('odometer_value') !== ''
                && VehicleDecimalValue::distance($this->input('odometer_value')) === null) {
                $validator->errors()->add('odometer_value', 'Enter a non-negative distance with up to 17 whole and 3 fractional digits.');
            }
        });
    }
}
