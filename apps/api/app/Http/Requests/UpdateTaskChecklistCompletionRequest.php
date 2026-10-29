<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTaskChecklistCompletionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'completed' => ['required', 'boolean'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ];
    }
}
