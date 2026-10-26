<?php

namespace App\Services\NetworkAccess;

use App\Models\HouseholdAuditEvent;
use App\Models\HouseholdSettings;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class NetworkAccessConfiguration
{
    /** @return array{enabled: bool, allowed_ip_ranges: list<string>, client_ip: ?string} */
    public function forAdmin(?string $clientIp): array
    {
        $ranges = $this->allowedRanges();

        return [
            'enabled' => $ranges !== [],
            'allowed_ip_ranges' => $ranges,
            'client_ip' => $clientIp,
        ];
    }

    public function allows(?string $clientIp): bool
    {
        return $this->includesClientAddress($this->allowedRanges(), $clientIp);
    }

    /** @param list<string> $ranges */
    public function includesClientAddress(array $ranges, ?string $clientIp): bool
    {
        if ($ranges === []) {
            return true;
        }

        if ($clientIp === null) {
            return false;
        }

        foreach ($ranges as $rangeValue) {
            $range = IpAddressRange::parse($rangeValue);
            if ($range?->contains($clientIp)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<mixed> $ranges */
    public function update(User $actor, array $ranges): void
    {
        $normalizedRanges = [];
        foreach ($ranges as $rangeValue) {
            $range = is_string($rangeValue) ? IpAddressRange::parse($rangeValue) : null;
            if ($range === null) {
                throw ValidationException::withMessages([
                    'allowed_ip_ranges' => 'Enter valid IP addresses or CIDR networks.',
                ]);
            }

            $normalizedRanges[] = $range->normalized();
        }
        $normalizedRanges = array_values(array_unique($normalizedRanges));

        DB::transaction(function () use ($actor, $normalizedRanges): void {
            $settings = HouseholdSettings::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            $currentActor = User::query()->whereKey($actor->id)->lockForUpdate()->first();
            abort_unless($currentActor instanceof User && $currentActor->is_active && $currentActor->is_admin, 403);

            $settings->ip_allowlist = $normalizedRanges;
            $settings->save();

            HouseholdAuditEvent::query()->create([
                'actor_user_id' => $currentActor->id,
                'subject_user_id' => $currentActor->id,
                'action' => 'household.network_access_settings_changed',
                'details' => [
                    'enabled' => $normalizedRanges !== [],
                    'entry_count' => count($normalizedRanges),
                ],
                'created_at' => now(),
            ]);
        }, 3);
    }

    /** @return list<string> */
    private function allowedRanges(): array
    {
        $ranges = HouseholdSettings::query()->find(1)?->ip_allowlist;

        if (! is_array($ranges)) {
            return [];
        }

        return array_values(array_filter($ranges, 'is_string'));
    }
}
