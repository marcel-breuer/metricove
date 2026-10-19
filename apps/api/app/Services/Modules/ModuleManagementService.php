<?php

namespace App\Services\Modules;

use App\Exceptions\ModuleNotFound;
use App\Exceptions\ModuleStateConflict;
use App\Models\HouseholdSettings;
use App\Models\ModuleState;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class ModuleManagementService
{
    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly ModuleStateService $states,
    ) {}

    /** @return array<string, mixed> */
    public function setEnabled(User $actor, string $moduleKey, bool $enabled): array
    {
        return DB::transaction(function () use ($actor, $moduleKey, $enabled): array {
            HouseholdSettings::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            $currentActor = User::query()->whereKey($actor->id)->lockForUpdate()->first();

            if (! $currentActor instanceof User || ! $currentActor->is_active || ! $currentActor->is_admin) {
                throw new AuthorizationException('Only an active household administrator can manage modules.');
            }

            $module = $this->registry->get($moduleKey);

            if ($module === null) {
                throw new ModuleNotFound($moduleKey);
            }

            if ($module->alwaysEnabled && ! $enabled) {
                throw new ModuleStateConflict('core_always_enabled', ['core']);
            }

            if ($enabled) {
                $missingDependencies = array_values(array_filter(
                    $module->dependencies,
                    fn (string $dependency): bool => ! $this->states->isEnabled($dependency),
                ));

                if ($missingDependencies !== []) {
                    throw new ModuleStateConflict('dependencies_disabled', $missingDependencies);
                }
            } else {
                $activeDependents = array_values(array_map(
                    fn (ModuleDefinition $dependent): string => $dependent->key,
                    array_filter(
                        $this->registry->dependentsOf($moduleKey),
                        fn (ModuleDefinition $dependent): bool => $this->states->isEnabled($dependent->key),
                    ),
                ));

                if ($activeDependents !== []) {
                    throw new ModuleStateConflict('dependents_enabled', $activeDependents);
                }
            }

            if ($this->states->isEnabled($moduleKey) !== $enabled && ! $module->alwaysEnabled) {
                ModuleState::query()->updateOrCreate(
                    ['module_key' => $moduleKey],
                    ['is_enabled' => $enabled],
                );
            }

            return $this->states->catalogEntry($moduleKey);
        }, 5);
    }
}
