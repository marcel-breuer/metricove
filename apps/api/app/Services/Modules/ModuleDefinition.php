<?php

namespace App\Services\Modules;

final readonly class ModuleDefinition
{
    /** @param list<string> $dependencies */
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public string $version,
        public array $dependencies,
        public bool $alwaysEnabled,
    ) {}

    /** @return array{key: string, label: string, description: string, version: string, dependencies: list<string>, always_enabled: bool} */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'description' => $this->description,
            'version' => $this->version,
            'dependencies' => $this->dependencies,
            'always_enabled' => $this->alwaysEnabled,
        ];
    }
}
