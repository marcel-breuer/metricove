<?php

namespace App\Services\Auth;

use App\Exceptions\InvitationUnavailable;
use App\Mail\InvitationMail;
use App\Models\HouseholdSettings;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class InvitationService
{
    public function invite(string $email, User $inviter): Invitation
    {
        return $this->issue($email, $inviter);
    }

    public function resend(int $invitationId, User $inviter): Invitation
    {
        return $this->issue(null, $inviter, $invitationId);
    }

    /** @return Collection<int, Invitation> */
    public function pending(User $viewer): Collection
    {
        return DB::transaction(function () use ($viewer): Collection {
            HouseholdSettings::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            $this->ensureActiveAdministrator($viewer->id);

            return Invitation::query()
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->orderByDesc('updated_at')
                ->get();
        }, 5);
    }

    public function revoke(int $invitationId, User $revoker): bool
    {
        return DB::transaction(function () use ($invitationId, $revoker): bool {
            HouseholdSettings::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            $this->ensureActiveAdministrator($revoker->id);

            $invitation = Invitation::query()
                ->whereKey($invitationId)
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->first();

            if (! $invitation instanceof Invitation) {
                return false;
            }

            $invitation->forceFill(['revoked_at' => now()])->save();

            return true;
        }, 3);
    }

    /** @param array{name: string, password: string, token: string} $attributes */
    public function claim(array $attributes): ?User
    {
        return DB::transaction(function () use ($attributes): ?User {
            HouseholdSettings::query()->whereKey(1)->lockForUpdate()->firstOrFail();

            $invitation = Invitation::query()
                ->where('token_hash', hash('sha256', $attributes['token']))
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->first();

            if (! $invitation instanceof Invitation || $invitation->expires_at->isPast()) {
                return null;
            }

            if (User::query()->whereRaw('LOWER(TRIM(email)) = ?', [$invitation->email])->exists()) {
                return null;
            }

            $user = User::query()->create([
                'name' => trim($attributes['name']),
                'email' => $invitation->email,
                'password' => $attributes['password'],
            ]);

            $user->forceFill([
                'email_verified_at' => now(),
                'is_admin' => false,
                'is_active' => true,
            ])->save();

            $invitation->forceFill(['accepted_at' => now()])->save();

            return $user;
        }, 3);
    }

    private function issue(?string $email, User $inviter, ?int $invitationId = null): Invitation
    {
        [$invitation, $token] = DB::transaction(function () use ($email, $inviter, $invitationId): array {
            // All account creation paths and invitation issuance share this lock.
            HouseholdSettings::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            $this->ensureActiveAdministrator($inviter->id);

            if ($invitationId !== null) {
                $invitation = Invitation::query()
                    ->whereKey($invitationId)
                    ->whereNull('accepted_at')
                    ->whereNull('revoked_at')
                    ->lockForUpdate()
                    ->first();

                if (! $invitation instanceof Invitation) {
                    throw new InvitationUnavailable;
                }

                $normalizedEmail = $invitation->email;
            } else {
                $normalizedEmail = Str::lower(trim((string) $email));
                $invitation = Invitation::query()
                    ->whereNull('accepted_at')
                    ->whereNull('revoked_at')
                    ->whereRaw('LOWER(TRIM(email)) = ?', [$normalizedEmail])
                    ->lockForUpdate()
                    ->first();
            }

            if (User::query()->whereRaw('LOWER(TRIM(email)) = ?', [$normalizedEmail])->exists()) {
                throw new InvitationUnavailable;
            }

            $token = Str::random(64);
            $invitation ??= new Invitation;
            $invitation->forceFill([
                'email' => $normalizedEmail,
                // The token has more than 256 bits of entropy; a SHA-256 digest permits indexed lookup.
                'token_hash' => hash('sha256', $token),
                'invited_by' => $inviter->id,
                'expires_at' => now()->addMinutes((int) config('auth.invitations.expire')),
                'accepted_at' => null,
                'revoked_at' => null,
            ])->save();

            return [$invitation, $token];
        }, 3);

        Mail::to($invitation->email)->send(new InvitationMail(
            $token,
            $invitation->expires_at->toDayDateTimeString(),
        ));

        return $invitation->refresh();
    }

    private function ensureActiveAdministrator(int $userId): void
    {
        $administrator = User::query()->whereKey($userId)->lockForUpdate()->first();

        if (! $administrator instanceof User || ! $administrator->is_active || ! $administrator->is_admin) {
            throw new AuthorizationException('Only an active household administrator can manage invitations.');
        }
    }
}
