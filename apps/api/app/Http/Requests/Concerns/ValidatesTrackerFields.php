<?php

namespace App\Http\Requests\Concerns;

use App\Services\Tracking\DecimalValue;
use Illuminate\Validation\Validator;

trait ValidatesTrackerFields
{
    protected function validateTrackerFieldDetails(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $fields = $this->input('fields');

            if (! is_array($fields)) {
                return;
            }

            foreach ($fields as $index => $field) {
                if (! is_array($field)) {
                    continue;
                }

                $type = $field['type'] ?? null;
                $unit = $field['unit'] ?? null;
                $minimum = $field['minimum'] ?? null;
                $maximum = $field['maximum'] ?? null;

                if ($type === 'number' && (! is_string($unit) || trim($unit) === '')) {
                    $validator->errors()->add("fields.{$index}.unit", 'A unit is required for numeric fields.');
                }

                if ($type !== 'number' && $unit !== null && $unit !== '') {
                    $validator->errors()->add("fields.{$index}.unit", 'Only numeric fields can have a unit.');
                }

                if ($type !== 'number' && ($minimum !== null || $maximum !== null)) {
                    $validator->errors()->add("fields.{$index}.minimum", 'Only numeric fields can have numeric limits.');
                }

                if ($minimum !== null && DecimalValue::normalize($minimum) === null) {
                    $validator->errors()->add("fields.{$index}.minimum", 'Enter a decimal with up to 14 whole and 6 fractional digits.');
                }

                if ($maximum !== null && DecimalValue::normalize($maximum) === null) {
                    $validator->errors()->add("fields.{$index}.maximum", 'Enter a decimal with up to 14 whole and 6 fractional digits.');
                }

                if ($minimum !== null
                    && $maximum !== null
                    && DecimalValue::normalize($minimum) !== null
                    && DecimalValue::normalize($maximum) !== null
                    && DecimalValue::compare((string) $minimum, (string) $maximum) > 0) {
                    $validator->errors()->add("fields.{$index}.maximum", 'The maximum must be at least the minimum.');
                }
            }
        });
    }
}
