<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreTrackerEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'schema_version' => ['required', 'integer', 'min:1'],
            'recorded_at' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:1000'],
            'values' => ['required', 'array'],
            'values.*' => ['nullable'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $values = $this->input('values');

            if (! is_array($values)) {
                return;
            }

            foreach ($values as $key => $value) {
                if (! is_string($key) || (! is_scalar($value) && $value !== null)) {
                    $validator->errors()->add("values.{$key}", 'Each field value must be a single text, number, date or boolean value.');
                }
            }
        });
    }
}
