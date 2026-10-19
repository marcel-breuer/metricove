<?php

namespace App\Services\Modules;

use App\Exceptions\ModuleDisabled;
use App\Models\ModuleState;

class ModuleStateService
{
    public function __construct(private readonly ModuleRegistry $registry) {}

    /** @return list<string> */
    public function enabledKeys(): array
    {
        $enabled = array_fill_keys(ModuleState::query()->where('is_enabled', true)->pluck('module_key')->all(), true);
        $enabled['core'] = true;

        return array_values(array_map(
            fn (ModuleDefinition $module): string => $module->key,
            array_filter($this->registry->all(), fn (ModuleDefinition $module): bool => isset($enabled[$module->key])),
        ));
    }

    public function isEnabled(string $key): bool
    {
        $module = $this->registry->get($key);

        if (! $module instanceof ModuleDefinition) {
            return false;
        }

        return $module->alwaysEnabled || ModuleState::query()->whereKey($key)->where('is_enabled', true)->exists();
    }

    public function assertEnabled(string $key): void
    {
        if (! $this->isEnabled($key)) {
            throw new ModuleDisabled($key);
        }
    }

    /** @return list<array{key: string, label: string, description: string, version: string, dependencies: list<string>, always_enabled: bool, enabled: bool, can_enable: bool, can_disable: bool, missing_dependencies: list<string>, active_dependents: list<string>}> */
    public function catalog(): array
    {
        $enabled = array_fill_keys(ModuleState::query()->where('is_enabled', true)->pluck('module_key')->all(), true);
        $enabled['core'] = true;

        return array_map(function (ModuleDefinition $module) use ($enabled): array {
            $missingDependencies = array_values(array_filter(
                $module->dependencies,
                fn (string $dependency): bool => ! isset($enabled[$dependency]),
            ));
            $activeDependents = array_values(array_map(
                fn (ModuleDefinition $dependent): string => $dependent->key,
                array_filter(
                    $this->registry->dependentsOf($module->key),
                    fn (ModuleDefinition $dependent): bool => isset($enabled[$dependent->key]),
                ),
            ));

            return [
                ...$module->toArray(),
                'enabled' => isset($enabled[$module->key]),
                'can_enable' => ! $module->alwaysEnabled && (isset($enabled[$module->key]) || $missingDependencies === []),
                'can_disable' => ! $module->alwaysEnabled && $activeDependents === [],
                'missing_dependencies' => $missingDependencies,
                'active_dependents' => $activeDependents,
            ];
        }, $this->registry->all());
    }

    /** @return array{key: string, label: string, description: string, version: string, dependencies: list<string>, always_enabled: bool, enabled: bool, can_enable: bool, can_disable: bool, missing_dependencies: list<string>, active_dependents: list<string>} */
    public function catalogEntry(string $key): array
    {
        foreach ($this->catalog() as $module) {
            if ($module['key'] === $key) {
                return $module;
            }
        }

        throw new \LogicException("The module {$key} disappeared from the registry.");
    }
}
