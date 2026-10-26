<?php

namespace App\Services\NetworkAccess;

final class IpAddressRange
{
    private function __construct(
        private readonly string $network,
        public readonly int $prefixLength,
        private readonly int $addressLength,
    ) {}

    public static function parse(string $value): ?self
    {
        $value = trim($value);
        if ($value === '' || str_contains($value, '%')) {
            return null;
        }

        $parts = explode('/', $value);
        if (count($parts) > 2) {
            return null;
        }

        $packedAddress = @inet_pton($parts[0]);
        if (! is_string($packedAddress)) {
            return null;
        }

        $addressLength = strlen($packedAddress);
        $maxPrefixLength = $addressLength * 8;
        if (isset($parts[1])) {
            if (preg_match('/\A\d{1,3}\z/', $parts[1]) !== 1) {
                return null;
            }

            $prefixLength = (int) $parts[1];
            if ($prefixLength > $maxPrefixLength) {
                return null;
            }
        } else {
            $prefixLength = $maxPrefixLength;
        }

        return new self(self::applyMask($packedAddress, $prefixLength), $prefixLength, $addressLength);
    }

    public function normalized(): string
    {
        $address = inet_ntop($this->network);
        if (! is_string($address)) {
            return '';
        }

        return $this->prefixLength === $this->addressLength * 8
            ? $address
            : $address.'/'.$this->prefixLength;
    }

    public function contains(string $address): bool
    {
        $packedAddress = @inet_pton(trim($address));
        if (! is_string($packedAddress) || strlen($packedAddress) !== $this->addressLength) {
            return false;
        }

        return self::applyMask($packedAddress, $this->prefixLength) === $this->network;
    }

    private static function applyMask(string $address, int $prefixLength): string
    {
        $masked = '';
        $byteLength = strlen($address);

        for ($index = 0; $index < $byteLength; $index++) {
            $remainingBits = $prefixLength - ($index * 8);
            $mask = $remainingBits >= 8
                ? 0xFF
                : ($remainingBits <= 0 ? 0 : (0xFF << (8 - $remainingBits)) & 0xFF);
            $masked .= chr(ord($address[$index]) & $mask);
        }

        return $masked;
    }
}
