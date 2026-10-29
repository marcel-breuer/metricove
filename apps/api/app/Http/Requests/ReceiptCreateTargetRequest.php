<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReceiptCreateTargetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'target_type' => ['required', 'in:finance,vehicle'],
            'finance_category_id' => ['required_if:target_type,finance', 'nullable', 'integer', 'min:1'],
            'vehicle_id' => ['required_if:target_type,vehicle', 'nullable', 'integer', 'min:1'],
            'vehicle_category' => ['required_if:target_type,vehicle', 'nullable', 'in:fuel,maintenance,insurance,tax,registration,finance,other'],
        ];
    }
}
