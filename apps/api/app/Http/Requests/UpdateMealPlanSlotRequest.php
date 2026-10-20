<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateMealPlanSlotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'planned_for' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'meal_type' => ['sometimes', 'required', 'in:breakfast,lunch,dinner'],
            'dish_type' => ['sometimes', 'required', 'in:free_text,recipe'],
            'dish_name' => ['sometimes', 'required_if:dish_type,free_text', 'nullable', 'string', 'max:180'],
            'recipe_id' => ['sometimes', 'required_if:dish_type,recipe', 'nullable', 'integer', 'min:1'],
            'servings' => ['sometimes', 'required', 'integer', 'between:1,1000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $dishType = $this->input('dish_type');

            if ($dishType === 'free_text' && $this->filled('recipe_id')) {
                $validator->errors()->add('recipe_id', 'A free-text meal cannot also link a recipe.');
            }

            if ($dishType === 'recipe' && $this->filled('dish_name')) {
                $validator->errors()->add('dish_name', 'A linked recipe supplies its own title.');
            }
        });
    }
}
