<?php

namespace App\Http\Requests;

use App\Services\Pantry\PantryQuantity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AdjustPantryStockRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('quantity_delta'))) {
            $this->merge(['quantity_delta' => trim($this->input('quantity_delta'))]);
        }

        if (is_string($this->input('note'))) {
            $note = trim($this->input('note'));
            $this->merge(['note' => $note === '' ? null : $note]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'expected_version' => ['required', 'integer', 'min:1'],
            'quantity_delta' => ['required', 'string', 'regex:/\A-?(?:0|[1-9]\d{0,10})(?:\.\d{1,3})?\z/'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->filled('quantity_delta') && PantryQuantity::delta($this->input('quantity_delta')) === null) {
                $validator->errors()->add('quantity_delta', 'Enter a non-zero adjustment with up to 11 whole and 3 fractional digits.');
            }
        });
    }
}
