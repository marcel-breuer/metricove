<?php

namespace App\Http\Requests;

use App\Services\Recipes\RecipeQuantity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use JsonException;

class StoreRecipeRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->decodeIngredients();

        if ($this->input('visibility') === null) {
            $this->merge(['visibility' => 'private']);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:180'],
            'category' => ['nullable', 'string', 'max:80'],
            'instructions' => ['required', 'string', 'max:20000'],
            'servings' => ['required', 'integer', 'between:1,1000'],
            'preparation_minutes' => ['nullable', 'integer', 'between:1,1440'],
            'visibility' => ['required', 'in:private,household'],
            'ingredients' => ['required', 'array', 'min:1', 'max:100'],
            'ingredients.*' => ['required', 'array:name,quantity,unit,note'],
            'ingredients.*.name' => ['required', 'string', 'max:120'],
            'ingredients.*.quantity' => ['required'],
            'ingredients.*.unit' => ['required', 'string', 'max:40'],
            'ingredients.*.note' => ['nullable', 'string', 'max:160'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $ingredients = $this->input('ingredients', []);

            if (! is_array($ingredients)) {
                return;
            }

            foreach ($ingredients as $index => $ingredient) {
                if (is_array($ingredient) && RecipeQuantity::normalize($ingredient['quantity'] ?? null) === null) {
                    $validator->errors()->add("ingredients.{$index}.quantity", 'Enter a positive quantity with up to 10 whole and 4 fractional digits.');
                }
            }
        });
    }

    private function decodeIngredients(): void
    {
        $ingredients = $this->input('ingredients');

        if (! is_string($ingredients)) {
            return;
        }

        try {
            $decoded = json_decode($ingredients, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return;
        }

        if (is_array($decoded)) {
            $this->merge(['ingredients' => $decoded]);
        }
    }
}
