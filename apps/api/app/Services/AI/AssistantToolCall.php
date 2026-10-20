<?php

namespace App\Services\AI;

final readonly class AssistantToolCall
{
    /** @param array<string, mixed> $arguments */
    public function __construct(
        public string $id,
        public string $name,
        public array $arguments,
        public string $rawArguments,
    ) {}
}
