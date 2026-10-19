<?php

namespace App\Services\Modules;

use App\Contracts\ModuleResource;
use App\Enums\ModuleCapability;
use App\Exceptions\ModuleDisabled;
use App\Exceptions\ModuleNotFound;
use App\Models\HouseholdSettings;
use App\Models\ModuleUserPermission;
use App\Models\ResourceUserPermission;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;

/** The shared authorization path for REST, jobs, AI and MCP module operations. */
class ModuleAccessService
{
    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly ModuleStateService $states,
    ) {}

    public function authorize(
        User $actor,
        string $moduleKey,
        ModuleCapability|string $capability,
        ?Model $resource = null,
    ): void {
        $capability = is_string($capability) ? ModuleCapability::tryFrom($capability) : $capability;

        if (! $capability instanceof ModuleCapability) {
            throw new AuthorizationException('The requested module capability is invalid.');
        }

        $module = $this->registry->get($moduleKey);

        if ($module === null) {
            throw new ModuleNotFound($moduleKey);
        }

        if (! $this->states->isEnabled($moduleKey)) {
            throw new ModuleDisabled($moduleKey);
        }

        if ($module->alwaysEnabled) {
            throw new AuthorizationException('Core access is governed by Core policies.');
        }

        $activeActor = User::query()->whereKey($actor->id)->where('is_active', true)->first();

        if (! $activeActor instanceof User) {
            throw new AuthorizationException('An active household member is required.');
        }

        if (! ModuleUserPermission::query()
            ->where('user_id', $activeActor->id)
            ->where('module_key', $moduleKey)
            ->where($capability->column(), true)
            ->exists()) {
            throw new AuthorizationException('The member does not have this module capability.');
        }

        if ($resource !== null) {
            $this->authorizeResource($activeActor, $moduleKey, $capability, $resource);
        }
    }

    /** Only the record owner with module-level manage permission can explicitly share a private resource. */
    public function grantPrivateResourceAccess(
        User $owner,
        Model $resource,
        User $member,
        ModuleCapability $capability,
    ): void {
        if (! $resource instanceof ModuleResource || ! $resource->isPrivate() || ! $resource->exists || $resource->getKey() === null) {
            throw new AuthorizationException('Only an existing private module resource can be shared.');
        }

        DB::transaction(function () use ($owner, $resource, $member, $capability): void {
            HouseholdSettings::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            $currentOwner = User::query()->whereKey($owner->id)->lockForUpdate()->first();
            $recipient = User::query()->whereKey($member->id)->lockForUpdate()->first();
            $currentResource = $this->freshResource($resource);

            if (! $currentOwner instanceof User
                || ! $currentOwner->is_active
                || ! $recipient instanceof User
                || ! $recipient->is_active
                || ! $currentResource->isPrivate()
                || $currentResource->ownerUserId() !== $currentOwner->id) {
                throw new AuthorizationException('Only the active record owner can grant private resource access.');
            }

            $this->authorize($currentOwner, $currentResource->moduleKey(), ModuleCapability::Manage, $currentResource);

            ResourceUserPermission::query()->firstOrCreate([
                'resource_type' => $currentResource->getMorphClass(),
                'resource_id' => $currentResource->getKey(),
                'user_id' => $recipient->id,
                'capability' => $capability->value,
            ]);
        }, 5);
    }

    /** Only the record owner with module-level manage permission can revoke a private-resource share. */
    public function revokePrivateResourceAccess(
        User $owner,
        Model $resource,
        User $member,
        ModuleCapability $capability,
    ): void {
        if (! $resource instanceof ModuleResource || ! $resource->isPrivate() || ! $resource->exists || $resource->getKey() === null) {
            throw new AuthorizationException('Only an existing private module resource can be managed.');
        }

        DB::transaction(function () use ($owner, $resource, $member, $capability): void {
            HouseholdSettings::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            $currentOwner = User::query()->whereKey($owner->id)->lockForUpdate()->first();
            $currentResource = $this->freshResource($resource);

            if (! $currentOwner instanceof User
                || ! $currentOwner->is_active
                || ! $currentResource->isPrivate()
                || $currentResource->ownerUserId() !== $currentOwner->id) {
                throw new AuthorizationException('Only the active record owner can revoke private resource access.');
            }

            $this->authorize($currentOwner, $currentResource->moduleKey(), ModuleCapability::Manage, $currentResource);

            ResourceUserPermission::query()
                ->where('resource_type', $currentResource->getMorphClass())
                ->where('resource_id', $currentResource->getKey())
                ->where('user_id', $member->id)
                ->where('capability', $capability->value)
                ->delete();
        }, 5);
    }

    private function authorizeResource(
        User $actor,
        string $moduleKey,
        ModuleCapability $capability,
        Model $resource,
    ): void {
        $resource = $this->freshResource($resource);

        if ($resource->moduleKey() !== $moduleKey) {
            throw new AuthorizationException('The resource does not belong to this module.');
        }

        if (! $resource->isPrivate()) {
            return;
        }

        $ownerId = $resource->ownerUserId();

        if ($ownerId === null) {
            throw new AuthorizationException('Private module resources must have an owner.');
        }

        if ($ownerId === $actor->id) {
            return;
        }

        $hasResourceGrant = $resource->exists
            && $resource->getKey() !== null
            && ResourceUserPermission::query()
                ->where('resource_type', $resource->getMorphClass())
                ->where('resource_id', $resource->getKey())
                ->where('user_id', $actor->id)
                ->where('capability', $capability->value)
                ->exists();

        if (! $hasResourceGrant) {
            throw new AuthorizationException('The private resource is not shared with this member.');
        }
    }

    private function freshResource(Model $resource): Model&ModuleResource
    {
        if (! $resource instanceof ModuleResource || ! $resource->exists || $resource->getKey() === null) {
            throw new LogicException('Module resources must be persisted and declare their ownership policy.');
        }

        $current = $resource->newQuery()->whereKey($resource->getKey())->first();

        if (! $current instanceof Model || ! $current instanceof ModuleResource) {
            throw new AuthorizationException('The module resource is unavailable.');
        }

        return $current;
    }
}
