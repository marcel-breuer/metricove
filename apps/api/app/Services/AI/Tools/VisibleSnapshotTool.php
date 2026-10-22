<?php

namespace App\Services\AI\Tools;

use App\Contracts\AssistantTool;
use App\Models\User;
use Closure;

class VisibleSnapshotTool implements AssistantTool
{
    /** @param Closure(User): array<string, mixed> $snapshot */
    public function __construct(
        private readonly string $key,
        private readonly string $toolName,
        private readonly string $description,
        private readonly Closure $snapshot,
    ) {}

    public function name(): string
    {
        return $this->toolName;
    }

    public function moduleKey(): string
    {
        return $this->key;
    }

    public function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->toolName,
                'description' => $this->description,
                'strict' => true,
                'parameters' => [
                    'type' => 'object',
                    'properties' => new \stdClass,
                    'required' => [],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    /** @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function execute(User $actor, array $arguments): array
    {
        if ($arguments !== []) {
            throw new \App\Exceptions\InvalidAssistantToolCall('The assistant tool received invalid arguments.');
        }

        return $this->bound(($this->snapshot)($actor));
    }

    /** @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private function bound(array $value, int $depth = 0): array
    {
        if ($depth >= 5) {
            return [];
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = array_is_list($item)
                    ? array_map(fn (mixed $entry): mixed => is_array($entry) ? $this->bound($entry, $depth + 1) : $this->boundValue($entry), array_slice($item, 0, 20))
                    : $this->bound($item, $depth + 1);
            } else {
                $value[$key] = $this->boundValue($item);
            }
        }

        return $value;
    }

    private function boundValue(mixed $value): mixed
    {
        return is_string($value) ? mb_substr($value, 0, 2000) : $value;
    }
}
