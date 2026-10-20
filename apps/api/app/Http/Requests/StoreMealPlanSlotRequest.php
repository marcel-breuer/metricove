<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreMealPlanSlotRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->input('visibility') === null) {
            $this->merge(['visibility' => 'private']);
        }

        if ($this->input('dish_type') === null) {
            $this->merge(['dish_type' => $this->filled('recipe_id') ? 'recipe' : 'free_text']);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'planned_for' => ['required', 'date_format:Y-m-d'],
            'meal_type' => ['required', 'in:breakfast,lunch,dinner'],
            'dish_type' => ['required', 'in:free_text,recipe'],
            'dish_name' => ['required_if:dish_type,free_text', 'nullable', 'string', 'max:180'],
            'recipe_id' => ['required_if:dish_type,recipe', 'nullable', 'integer', 'min:1'],
            'servings' => ['required', 'integer', 'between:1,1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'visibility' => ['required', 'in:private,household'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('dish_type') === 'free_text' && $this->filled('recipe_id')) {
                $validator->errors()->add('recipe_id', 'A free-text meal cannot also link a recipe.');
            }

            if ($this->input('dish_type') === 'recipe' && $this->filled('dish_name')) {
                $validator->errors()->add('dish_name', 'A linked recipe supplies its own title.');
            }
        });
    }
}
