<?php

namespace App\Services\NetworkAccess;

use Illuminate\Http\Request;

class ClientIpResolver
{
    public function resolve(Request $request): ?string
    {
        $remoteAddress = $this->normalizeHostAddress($request->server('REMOTE_ADDR'));
        if ($remoteAddress === null || ! $this->isTrustedProxy($remoteAddress)) {
            return $remoteAddress;
        }

        $forwardedFor = $request->headers->get('X-Forwarded-For');
        if (! is_string($forwardedFor) || trim($forwardedFor) === '' || strlen($forwardedFor) > 4096) {
            return null;
        }

        $forwardedAddresses = explode(',', $forwardedFor);
        if (count($forwardedAddresses) > 32) {
            return null;
        }

        foreach (array_reverse($forwardedAddresses) as $address) {
            $candidate = $this->normalizeHostAddress($address);
            if ($candidate === null) {
                return null;
            }

            if (! $this->isTrustedProxy($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function normalizeHostAddress(mixed $address): ?string
    {
        if (! is_string($address)) {
            return null;
        }

        $range = IpAddressRange::parse(trim($address));
        if ($range === null || str_contains($address, '/')) {
            return null;
        }

        return $range->normalized();
    }

    private function isTrustedProxy(string $address): bool
    {
        $trustedProxies = config('network_access.trusted_proxies', []);
        if (! is_array($trustedProxies)) {
            return false;
        }

        foreach ($trustedProxies as $trustedProxy) {
            if (! is_string($trustedProxy)) {
                continue;
            }

            $range = IpAddressRange::parse($trustedProxy);
            if ($range?->contains($address)) {
                return true;
            }
        }

        return false;
    }
}
