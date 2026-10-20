<?php

namespace App\Services\AI;

final readonly class AssistantCompletionRequest
{
    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>  $tools
     */
    public function __construct(
        public array $messages,
        public array $tools,
    ) {}
}
