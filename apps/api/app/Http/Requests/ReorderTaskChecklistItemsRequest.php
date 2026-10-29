<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReorderTaskChecklistItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'item_ids' => ['required', 'array', 'max:100'],
            'item_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ];
    }
}
