<?php

namespace Tests\Unit;

use App\Services\NetworkAccess\IpAddressRange;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class IpAddressRangeTest extends TestCase
{
    #[DataProvider('validRanges')]
    public function test_it_normalizes_ip_addresses_and_cidr_networks(string $input, string $expected): void
    {
        $range = IpAddressRange::parse($input);

        $this->assertNotNull($range);
        $this->assertSame($expected, $range->normalized());
    }

    public static function validRanges(): array
    {
        return [
            'exact IPv4 address' => ['192.0.2.17', '192.0.2.17'],
            'IPv4 CIDR host bits are cleared' => ['192.0.2.17/24', '192.0.2.0/24'],
            'IPv4 default route' => ['0.0.0.0/0', '0.0.0.0/0'],
            'exact IPv6 address' => ['2001:0db8::1', '2001:db8::1'],
            'IPv6 CIDR host bits are cleared' => ['2001:db8:abcd::1/48', '2001:db8:abcd::/48'],
        ];
    }

    #[DataProvider('matchingRanges')]
    public function test_it_matches_addresses_inside_a_range(string $rangeValue, string $address, bool $matches): void
    {
        $range = IpAddressRange::parse($rangeValue);

        $this->assertNotNull($range);
        $this->assertSame($matches, $range->contains($address));
    }

    public static function matchingRanges(): array
    {
        return [
            'exact IPv4 match' => ['192.0.2.17', '192.0.2.17', true],
            'exact IPv4 mismatch' => ['192.0.2.17', '192.0.2.18', false],
            'IPv4 in CIDR' => ['192.0.2.0/24', '192.0.2.250', true],
            'IPv4 outside CIDR' => ['192.0.2.0/24', '192.0.3.1', false],
            'IPv6 in CIDR' => ['2001:db8:abcd::/48', '2001:db8:abcd:1::5', true],
            'different address family' => ['192.0.2.0/24', '2001:db8::1', false],
        ];
    }

    #[DataProvider('invalidRanges')]
    public function test_it_rejects_invalid_ip_and_cidr_values(string $input): void
    {
        $this->assertNull(IpAddressRange::parse($input));
    }

    public static function invalidRanges(): array
    {
        return [
            'invalid IPv4' => ['192.0.2.999'],
            'invalid address' => ['not-an-ip'],
            'invalid prefix' => ['192.0.2.1/33'],
            'invalid IPv6 prefix' => ['2001:db8::1/129'],
            'too many CIDR separators' => ['192.0.2.0/24/1'],
            'zone identifier' => ['fe80::1%en0'],
        ];
    }
}
