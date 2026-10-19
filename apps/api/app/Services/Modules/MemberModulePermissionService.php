<?php

namespace App\Services\Modules;

use App\Enums\ModuleCapability;
use App\Exceptions\ModuleNotFound;
use App\Exceptions\ModuleStateConflict;
use App\Models\HouseholdAuditEvent;
use App\Models\HouseholdSettings;
use App\Models\ModuleUserPermission;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class MemberModulePermissionService
{
    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly ModuleStateService $states,
    ) {}

    /** @return array{id: int, name: string, modules: list<array<string, mixed>>} */
    public function forMember(User $actor, int $memberId): array
    {
        return DB::transaction(function () use ($actor, $memberId): array {
            $this->lockAndAuthorizeAdministrator($actor);
            $member = User::query()->whereKey($memberId)->lockForUpdate()->firstOrFail();
            $grants = ModuleUserPermission::query()->where('user_id', $member->id)->get()->keyBy('module_key');

            $modules = [];

            foreach ($this->registry->all() as $module) {
                if ($module->alwaysEnabled) {
                    continue;
                }

                $grant = $grants->get($module->key);
                $modules[] = [
                    ...$module->toArray(),
                    'enabled' => $this->states->isEnabled($module->key),
                    'capabilities' => $grant instanceof ModuleUserPermission
                        ? $grant->capabilities()
                        : $this->emptyCapabilities(),
                ];
            }

            return ['id' => $member->id, 'name' => $member->name, 'modules' => $modules];
        }, 5);
    }

    /** @param array{view: bool, create: bool, edit: bool, manage: bool} $capabilities
     * @return array<string, mixed>
     */
    public function updateForMember(User $actor, int $memberId, string $moduleKey, array $capabilities): array
    {
        return DB::transaction(function () use ($actor, $memberId, $moduleKey, $capabilities): array {
            $currentActor = $this->lockAndAuthorizeAdministrator($actor);
            $member = User::query()->whereKey($memberId)->lockForUpdate()->firstOrFail();
            $module = $this->registry->get($moduleKey);

            if ($module === null) {
                throw new ModuleNotFound($moduleKey);
            }

            if ($module->alwaysEnabled) {
                throw new ModuleStateConflict('core_permissions_managed_by_core', ['core']);
            }

            $permission = ModuleUserPermission::query()
                ->where('user_id', $member->id)
                ->where('module_key', $module->key)
                ->lockForUpdate()
                ->first();

            $before = $permission instanceof ModuleUserPermission
                ? $permission->capabilities()
                : $this->emptyCapabilities();
            $after = [];

            foreach (ModuleCapability::cases() as $capability) {
                $value = $capabilities[$capability->value] ?? null;

                if (! in_array($value, [true, false, 1, 0, '1', '0'], true)) {
                    throw new \InvalidArgumentException('Every module capability must be an explicit boolean.');
                }

                $after[$capability->value] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            }

            $permission = ModuleUserPermission::query()->updateOrCreate(
                ['user_id' => $member->id, 'module_key' => $module->key],
                [
                    'can_view' => $after['view'],
                    'can_create' => $after['create'],
                    'can_edit' => $after['edit'],
                    'can_manage' => $after['manage'],
                ],
            );

            if ($before !== $after) {
                HouseholdAuditEvent::query()->create([
                    'actor_user_id' => $currentActor->id,
                    'subject_user_id' => $member->id,
                    'action' => 'member.module_permissions_changed',
                    'details' => [
                        'module_key' => $module->key,
                        'before' => $before,
                        'after' => $after,
                    ],
                    'created_at' => now(),
                ]);
            }

            return [
                ...$module->toArray(),
                'enabled' => $this->states->isEnabled($module->key),
                'capabilities' => $permission->capabilities(),
            ];
        }, 5);
    }

    private function lockAndAuthorizeAdministrator(User $actor): User
    {
        HouseholdSettings::query()->whereKey(1)->lockForUpdate()->firstOrFail();
        $currentActor = User::query()->whereKey($actor->id)->lockForUpdate()->first();

        if (! $currentActor instanceof User || ! $currentActor->is_active || ! $currentActor->is_admin) {
            throw new AuthorizationException('Only an active household administrator can manage module permissions.');
        }

        return $currentActor;
    }

    /** @return array{view: bool, create: bool, edit: bool, manage: bool} */
    private function emptyCapabilities(): array
    {
        return ['view' => false, 'create' => false, 'edit' => false, 'manage' => false];
    }
}
