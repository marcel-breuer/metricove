<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'make' => ['required', 'string', 'max:80'],
            'model' => ['required', 'string', 'max:80'],
            'model_year' => ['nullable', 'integer', 'min:1886', 'max:2200'],
            'fuel_type' => ['required', 'in:petrol,diesel,hybrid,electric,lpg,other'],
            'odometer_unit' => ['required', 'in:km,mi'],
            'description' => ['nullable', 'string', 'max:2000'],
            'visibility' => ['required', 'in:private,household'],
        ];
    }
}
