<?php

namespace App\Http\Requests;

use App\Services\NetworkAccess\IpAddressRange;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateNetworkAccessSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'allowed_ip_ranges' => ['present', 'array', 'max:100'],
            'allowed_ip_ranges.*' => ['string', 'max:64'],
            'confirm_lockout' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $ranges = $this->input('allowed_ip_ranges');
            if (! is_array($ranges)) {
                return;
            }

            foreach ($ranges as $index => $range) {
                if (is_string($range) && IpAddressRange::parse($range) !== null) {
                    continue;
                }

                $validator->errors()->add("allowed_ip_ranges.{$index}", 'Enter a valid IPv4 or IPv6 address or CIDR network.');
            }
        }];
    }
}
