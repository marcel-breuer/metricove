<?php

namespace App\Services\Auth;

use App\Exceptions\LastActiveAdministrator;
use App\Models\HouseholdAuditEvent;
use App\Models\HouseholdSettings;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class MemberManagementService
{
    /** @return Collection<int, User> */
    public function members(User $actor): Collection
    {
        return DB::transaction(function () use ($actor): Collection {
            $this->authorizeAndLockActor($actor);

            return User::query()->orderBy('id')->get();
        }, 5);
    }

    /** @return Collection<int, HouseholdAuditEvent> */
    public function auditEvents(User $actor): Collection
    {
        return DB::transaction(function () use ($actor): Collection {
            $this->authorizeAndLockActor($actor);

            return HouseholdAuditEvent::query()
                ->orderByDesc('id')
                ->limit(100)
                ->get();
        }, 5);
    }

    /** @param array{is_admin?: bool, is_active?: bool} $changes */
    public function update(User $actor, int $memberId, array $changes): User
    {
        return DB::transaction(function () use ($actor, $memberId, $changes): User {
            $currentActor = $this->authorizeAndLockActor($actor);
            $member = User::query()->whereKey($memberId)->lockForUpdate()->firstOrFail();

            $before = [
                'is_admin' => $member->is_admin,
                'is_active' => $member->is_active,
            ];
            $after = [
                'is_admin' => array_key_exists('is_admin', $changes) ? (bool) $changes['is_admin'] : $member->is_admin,
                'is_active' => array_key_exists('is_active', $changes) ? (bool) $changes['is_active'] : $member->is_active,
            ];

            if ($before === $after) {
                return $member;
            }

            if ($before['is_admin'] && $before['is_active'] && ! ($after['is_admin'] && $after['is_active'])) {
                $this->ensureAnotherActiveAdministratorExists();
            }

            $member->forceFill($after)->save();

            $action = match (array_keys(array_diff_assoc($after, $before))) {
                ['is_admin'] => $after['is_admin'] ? 'member.promoted' : 'member.demoted',
                ['is_active'] => $after['is_active'] ? 'member.activated' : 'member.suspended',
                default => 'member.access_changed',
            };

            $this->record($currentActor->id, $member->id, $action, [
                'before' => $before,
                'after' => $after,
            ]);

            return $member->refresh();
        }, 5);
    }

    public function remove(User $actor, int $memberId): void
    {
        DB::transaction(function () use ($actor, $memberId): void {
            $currentActor = $this->authorizeAndLockActor($actor);
            $member = User::query()->whereKey($memberId)->lockForUpdate()->firstOrFail();

            if ($member->is_admin && $member->is_active) {
                $this->ensureAnotherActiveAdministratorExists();
            }

            $this->record($currentActor->id, $member->id, 'member.removed');
            $member->delete();
        }, 5);
    }

    public function recoverAdministrator(int $memberId): User
    {
        return DB::transaction(function () use ($memberId): User {
            HouseholdSettings::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            $member = User::query()->whereKey($memberId)->lockForUpdate()->firstOrFail();
            $before = [
                'is_admin' => $member->is_admin,
                'is_active' => $member->is_active,
            ];
            $after = ['is_admin' => true, 'is_active' => true];

            if ($before !== $after) {
                $member->forceFill($after)->save();
                $this->record(null, $member->id, 'admin.recovered', [
                    'before' => $before,
                    'after' => $after,
                    'source' => 'console',
                ]);
            }

            return $member->refresh();
        }, 5);
    }

    private function authorizeAndLockActor(User $actor): User
    {
        HouseholdSettings::query()->whereKey(1)->lockForUpdate()->firstOrFail();
        $currentActor = User::query()->whereKey($actor->id)->lockForUpdate()->first();

        if (! $currentActor instanceof User || ! $currentActor->is_active || ! $currentActor->is_admin) {
            throw new AuthorizationException('Only an active household administrator can manage members.');
        }

        return $currentActor;
    }

    private function ensureAnotherActiveAdministratorExists(): void
    {
        $activeAdmins = User::query()
            ->where('is_admin', true)
            ->where('is_active', true)
            ->count();

        if ($activeAdmins <= 1) {
            throw new LastActiveAdministrator;
        }
    }

    /** @param array<string, mixed>|null $details */
    private function record(?int $actorId, int $subjectId, string $action, ?array $details = null): void
    {
        HouseholdAuditEvent::query()->create([
            'actor_user_id' => $actorId,
            'subject_user_id' => $subjectId,
            'action' => $action,
            'details' => $details,
            'created_at' => now(),
        ]);
    }
}
