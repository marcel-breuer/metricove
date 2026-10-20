<?php

namespace App\Services\AI;

use App\Contracts\AssistantTool;
use App\Enums\ModuleCapability;
use App\Exceptions\InvalidAssistantToolCall;
use App\Exceptions\ModuleDisabled;
use App\Models\User;
use App\Services\Modules\ModuleAccessService;
use Illuminate\Auth\Access\AuthorizationException;

class AssistantToolRegistry
{
    /** @param list<AssistantTool> $tools */
    public function __construct(
        private readonly ModuleAccessService $access,
        private readonly array $tools,
    ) {}

    /** @return list<AssistantTool> */
    public function availableFor(User $actor): array
    {
        return array_values(array_filter($this->tools, function (AssistantTool $tool) use ($actor): bool {
            try {
                $this->access->authorize($actor, $tool->moduleKey(), ModuleCapability::View);

                return true;
            } catch (AuthorizationException|ModuleDisabled) {
                return false;
            }
        }));
    }

    /** @return list<array<string, mixed>> */
    public function definitionsFor(User $actor): array
    {
        return array_map(
            fn (AssistantTool $tool): array => $tool->definition(),
            $this->availableFor($actor),
        );
    }

    /** @return list<string> */
    public function modulesFor(User $actor): array
    {
        return array_values(array_unique(array_map(
            fn (AssistantTool $tool): string => $tool->moduleKey(),
            $this->availableFor($actor),
        )));
    }

    /** @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function execute(User $actor, string $name, array $arguments): array
    {
        foreach ($this->availableFor($actor) as $tool) {
            if ($tool->name() === $name) {
                try {
                    $this->access->authorize($actor, $tool->moduleKey(), ModuleCapability::View);
                } catch (AuthorizationException|ModuleDisabled) {
                    throw new InvalidAssistantToolCall('The requested assistant tool is unavailable.');
                }

                return $tool->execute($actor, $arguments);
            }
        }

        throw new InvalidAssistantToolCall('The requested assistant tool is unavailable.');
    }
}
