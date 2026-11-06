<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class NotionFoodImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'recipes' => ['sometimes', 'array', 'max:200'],
            'recipes.*' => ['required', 'array'],
            'recipes.*.source_id' => ['required', 'string', 'max:180'],
            'recipes.*.title' => ['nullable', 'string', 'max:180'],
            'recipes.*.category' => ['nullable', 'string', 'max:80'],
            'recipes.*.instructions' => ['nullable'],
            'recipes.*.servings' => ['nullable', 'integer', 'between:1,1000'],
            'recipes.*.preparation_minutes' => ['nullable', 'integer', 'between:1,1440'],
            'recipes.*.ingredients' => ['sometimes', 'array', 'max:100'],
            'recipes.*.ingredients.*' => ['required', 'array'],
            'recipes.*.ingredients.*.name' => ['nullable', 'string', 'max:120'],
            'recipes.*.ingredients.*.quantity' => ['nullable'],
            'recipes.*.ingredients.*.unit' => ['nullable', 'string', 'max:40'],
            'recipes.*.ingredients.*.note' => ['nullable', 'string', 'max:160'],
            'meal_plans' => ['sometimes', 'array', 'max:500'],
            'meal_plans.*' => ['required', 'array'],
            'meal_plans.*.source_id' => ['required', 'string', 'max:180'],
            'meal_plans.*.planned_for' => ['nullable', 'string', 'max:32'],
            'meal_plans.*.meal_type' => ['nullable', 'string', 'max:32'],
            'meal_plans.*.dish_name' => ['nullable', 'string', 'max:180'],
            'meal_plans.*.servings' => ['nullable', 'integer', 'between:1,1000'],
            'meal_plans.*.notes' => ['nullable', 'string', 'max:2000'],
            'selected_source_ids' => ['sometimes', 'array', 'max:700'],
            'selected_source_ids.*' => ['required', 'string', 'max:180'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $recipes = $this->input('recipes', []);
            $plans = $this->input('meal_plans', []);
            if (! is_array($recipes) || ! is_array($plans)) {
                return;
            }
            $sources = [];
            foreach (array_merge($recipes, $plans) as $record) {
                if (! is_array($record) || ! is_string($record['source_id'] ?? null)) {
                    continue;
                }
                if (isset($sources[$record['source_id']])) {
                    $validator->errors()->add('source_id', 'Each source page may appear only once in an import file.');

                    return;
                }
                $sources[$record['source_id']] = true;
            }
            if ($recipes === [] && $plans === []) {
                $validator->errors()->add('recipes', 'The import file must contain at least one recipe or meal plan entry.');
            }
        });
    }
}
