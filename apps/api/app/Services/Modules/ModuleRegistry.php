<?php

namespace App\Services\Modules;

use Illuminate\Support\Arr;
use LogicException;

class ModuleRegistry
{
    /** @var array<string, ModuleDefinition> */
    private array $definitions;

    public function __construct()
    {
        $catalog = config('modules.catalog', []);

        if (! is_array($catalog)) {
            throw new LogicException('The module catalog must be an array.');
        }

        $this->definitions = [];

        foreach ($catalog as $key => $values) {
            if (! is_string($key) || ! preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key) || ! is_array($values)) {
                throw new LogicException('Module keys and definitions must be valid.');
            }

            $label = Arr::get($values, 'label');
            $description = Arr::get($values, 'description');
            $version = Arr::get($values, 'version');
            $dependencies = Arr::get($values, 'dependencies', []);
            $alwaysEnabled = Arr::get($values, 'always_enabled', false);

            if (! is_string($label) || ! is_string($description) || ! is_string($version) || ! is_array($dependencies) || ! is_bool($alwaysEnabled)) {
                throw new LogicException("The module definition for {$key} is incomplete.");
            }

            $dependencies = array_values(array_unique($dependencies));

            foreach ($dependencies as $dependency) {
                if (! is_string($dependency) || ! preg_match('/^[a-z][a-z0-9_]{0,63}$/', $dependency)) {
                    throw new LogicException("The module definition for {$key} has an invalid dependency.");
                }
            }

            $this->definitions[$key] = new ModuleDefinition(
                key: $key,
                label: $label,
                description: $description,
                version: $version,
                dependencies: $dependencies,
                alwaysEnabled: $alwaysEnabled,
            );
        }

        $this->validateCatalog();
    }

    /** @return list<ModuleDefinition> */
    public function all(): array
    {
        return array_values($this->definitions);
    }

    public function get(string $key): ?ModuleDefinition
    {
        return $this->definitions[$key] ?? null;
    }

    /** @return list<ModuleDefinition> */
    public function dependentsOf(string $key): array
    {
        return array_values(array_filter(
            $this->definitions,
            fn (ModuleDefinition $module): bool => in_array($key, $module->dependencies, true),
        ));
    }

    private function validateCatalog(): void
    {
        $core = $this->definitions['core'] ?? null;

        if (! $core instanceof ModuleDefinition || ! $core->alwaysEnabled) {
            throw new LogicException('The core module must be registered and always enabled.');
        }

        foreach ($this->definitions as $module) {
            if ($module->key !== 'core' && $module->alwaysEnabled) {
                throw new LogicException('Only the core module may be always enabled.');
            }

            foreach ($module->dependencies as $dependency) {
                if ($dependency === $module->key || ! isset($this->definitions[$dependency])) {
                    throw new LogicException("The module dependency {$dependency} for {$module->key} is not registered.");
                }
            }
        }

        $visited = [];
        $visiting = [];

        $visit = function (string $key) use (&$visit, &$visited, &$visiting): void {
            if (isset($visited[$key])) {
                return;
            }

            if (isset($visiting[$key])) {
                throw new LogicException("The module catalog contains a dependency cycle at {$key}.");
            }

            $visiting[$key] = true;
            foreach ($this->definitions[$key]->dependencies as $dependency) {
                $visit($dependency);
            }
            unset($visiting[$key]);
            $visited[$key] = true;
        };

        foreach (array_keys($this->definitions) as $key) {
            $visit($key);
        }
    }
}
