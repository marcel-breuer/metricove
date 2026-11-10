<?php

namespace App\Http\Requests;

use App\Services\Recipes\RecipeQuantity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
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
            'instructions' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'servings' => ['sometimes', 'nullable', 'integer', 'between:1,1000'],
            'preparation_minutes' => ['sometimes', 'nullable', 'integer', 'between:1,1440'],
            'visibility' => ['sometimes', 'required', 'in:private,household'],
            'ingredients' => ['sometimes', 'present', 'array', 'max:100'],
            'ingredients.*' => ['required', 'array:name,quantity,unit,note'],
            'ingredients.*.name' => ['required', 'string', 'max:120'],
            'ingredients.*.quantity' => ['nullable'],
            'ingredients.*.unit' => ['nullable', 'string', 'max:40'],
            'ingredients.*.note' => ['nullable', 'string', 'max:160'],
            'photo' => $this->photoRules(),
            'remove_photo' => ['sometimes', 'boolean'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $ingredients = $this->input('ingredients', []);

            if (is_array($ingredients)) {
                foreach ($ingredients as $index => $ingredient) {
                    if (! is_array($ingredient)) {
                        continue;
                    }
                    $quantity = RecipeQuantity::normalize($ingredient['quantity'] ?? null);
                    $unit = trim((string) ($ingredient['unit'] ?? ''));
                    if (($quantity === null) !== ($unit === '')) {
                        $validator->errors()->add("ingredients.{$index}.quantity", 'Enter a positive quantity with up to 10 whole and 4 fractional digits.');
                    }
                }
            }

            if ($this->boolean('remove_photo') && $this->hasFile('photo')) {
                $validator->errors()->add('photo', 'Choose a photo or remove the existing one, not both.');
            }
        });
    }

    /** @return list<string> */
    private function photoRules(): array
    {
        $file = $this->file('photo');

        if ($file instanceof UploadedFile && $file->getMimeType() === 'application/pdf') {
            return ['nullable', 'file', 'mimes:pdf', 'mimetypes:application/pdf', 'max:10240'];
        }

        return ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'];
    }
}
