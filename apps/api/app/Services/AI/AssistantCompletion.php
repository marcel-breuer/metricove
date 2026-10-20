<?php

namespace App\Services\AI;

final readonly class AssistantCompletion
{
    /** @param list<AssistantToolCall> $toolCalls */
    public function __construct(
        public ?string $content,
        public array $toolCalls = [],
    ) {}
}
