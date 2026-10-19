<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RecordFinanceRecurringEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'occurrence_on' => ['required', 'date_format:Y-m-d'],
            'occurred_on' => ['sometimes', 'required', 'date_format:Y-m-d'],
        ];
    }
}
