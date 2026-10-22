<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateAiProviderSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'provider' => ['required', Rule::in(['disabled', 'openai-compatible'])],
            'base_url' => ['nullable', 'string', 'url', 'max:2048'],
            'model' => ['nullable', 'string', 'max:120'],
            'api_key' => ['sometimes', 'nullable', 'string', 'max:4096'],
            'clear_api_key' => ['sometimes', 'boolean'],
            'external_provider' => ['required', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->input('provider') !== 'openai-compatible') {
                return;
            }

            if (! is_string($this->input('base_url')) || trim($this->input('base_url')) === '') {
                $validator->errors()->add('base_url', 'Enter the provider base URL.');

                return;
            }
            if (! is_string($this->input('model')) || trim($this->input('model')) === '') {
                $validator->errors()->add('model', 'Enter the provider model name.');
            }

            $parts = parse_url(trim((string) $this->input('base_url')));
            if (! is_array($parts)
                || ! is_string($parts['host'] ?? null)
                || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
                || ($parts['user'] ?? null) !== null
                || ($parts['pass'] ?? null) !== null
                || ($parts['query'] ?? null) !== null
                || ($parts['fragment'] ?? null) !== null) {
                $validator->errors()->add('base_url', 'Use a provider base URL without credentials, query or fragment.');
            } elseif ($this->boolean('external_provider') && strtolower((string) $parts['scheme']) !== 'https') {
                $validator->errors()->add('base_url', 'External providers must use HTTPS.');
            }
        }];
    }
}
