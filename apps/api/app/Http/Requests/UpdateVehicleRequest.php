<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'make' => ['sometimes', 'required', 'string', 'max:80'],
            'model' => ['sometimes', 'required', 'string', 'max:80'],
            'model_year' => ['sometimes', 'nullable', 'integer', 'min:1886', 'max:2200'],
            'fuel_type' => ['sometimes', 'required', 'in:petrol,diesel,hybrid,electric,lpg,other'],
            'odometer_unit' => ['sometimes', 'required', 'in:km,mi'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->all() === []) {
                $validator->errors()->add('vehicle', 'Provide at least one vehicle field to update.');
            }
        });
    }
}
