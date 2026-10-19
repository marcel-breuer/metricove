<?php

namespace App\Http\Requests;

use App\Services\Recipes\RecipeQuantity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use JsonException;

class UpdateRecipeRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $ingredients = $this->input('ingredients');

        if (is_string($ingredients)) {
            try {
                $decoded = json_decode($ingredients, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $this->merge(['ingredients' => $decoded]);
                }
            } catch (JsonException) {
                // The array rule returns a validation error for malformed multipart JSON.
            }
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:180'],
            'category' => ['sometimes', 'nullable', 'string', 'max:80'],
            'instructions' => ['sometimes', 'required', 'string', 'max:20000'],
            'servings' => ['sometimes', 'required', 'integer', 'between:1,1000'],
            'preparation_minutes' => ['sometimes', 'nullable', 'integer', 'between:1,1440'],
            'ingredients' => ['sometimes', 'required', 'array', 'min:1', 'max:100'],
            'ingredients.*' => ['required', 'array:name,quantity,unit,note'],
            'ingredients.*.name' => ['required', 'string', 'max:120'],
            'ingredients.*.quantity' => ['required'],
            'ingredients.*.unit' => ['required', 'string', 'max:40'],
            'ingredients.*.note' => ['nullable', 'string', 'max:160'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'remove_photo' => ['sometimes', 'boolean'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $ingredients = $this->input('ingredients', []);

            if (is_array($ingredients)) {
                foreach ($ingredients as $index => $ingredient) {
                    if (is_array($ingredient) && RecipeQuantity::normalize($ingredient['quantity'] ?? null) === null) {
                        $validator->errors()->add("ingredients.{$index}.quantity", 'Enter a positive quantity with up to 10 whole and 4 fractional digits.');
                    }
                }
            }

            if ($this->boolean('remove_photo') && $this->hasFile('photo')) {
                $validator->errors()->add('photo', 'Choose a photo or remove the existing one, not both.');
            }
        });
    }
}
