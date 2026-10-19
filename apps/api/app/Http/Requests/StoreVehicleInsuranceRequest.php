<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreVehicleInsuranceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'provider' => ['required', 'string', 'max:120'],
            'coverage_type' => ['required', 'in:liability,partial,comprehensive,other'],
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'valid_until' => ['required', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
