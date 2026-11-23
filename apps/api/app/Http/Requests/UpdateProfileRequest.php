<?php

namespace App\Http\Requests;

use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $name = $this->input('name');
        $email = $this->input('email');
        $recipeUnits = $this->input('recipe_units');

        $this->merge([
            'name' => is_string($name) ? trim($name) : $name,
            'email' => is_string($email) ? Str::lower(trim($email)) : $email,
            'recipe_units' => is_array($recipeUnits)
                ? array_map(static fn (mixed $unit): mixed => is_string($unit) ? trim($unit) : $unit, $recipeUnits)
                : $recipeUnits,
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $duplicate = User::query()
                        ->whereRaw('LOWER(TRIM(email)) = ?', [Str::lower(trim((string) $value))])
                        ->where('id', '!=', $this->user()->getAuthIdentifier())
                        ->exists();

                    if ($duplicate) {
                        $fail('The email address is already in use.');
                    }
                },
            ],
            'language' => ['sometimes', 'required', 'in:en,de'],
            'number_locale' => ['sometimes', 'required', 'in:de-DE,en-US,en-GB'],
            'default_currency' => ['sometimes', 'required', 'in:EUR,USD,GBP,CHF'],
            'currency_display' => ['sometimes', 'required', 'in:code,symbol'],
            'recipe_units' => ['sometimes', 'required', 'array', 'min:1', 'max:80'],
            'recipe_units.*' => ['required', 'string', 'max:40', 'distinct:ignore_case'],
        ];
    }
}
