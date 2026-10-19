<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesTrackerFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreTrackerRequest extends FormRequest
{
    use ValidatesTrackerFields;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'visibility' => ['required', 'in:private,household'],
            'fields' => ['required', 'array', 'min:1', 'max:50'],
            'fields.*' => ['required', 'array:label,type,unit,is_required,minimum,maximum'],
            'fields.*.label' => ['required', 'string', 'max:120'],
            'fields.*.type' => ['required', 'in:number,text,date,boolean'],
            'fields.*.unit' => ['nullable', 'string', 'max:32'],
            'fields.*.is_required' => ['required', 'boolean'],
            'fields.*.minimum' => ['nullable'],
            'fields.*.maximum' => ['nullable'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $this->validateTrackerFieldDetails($validator);
    }
}
