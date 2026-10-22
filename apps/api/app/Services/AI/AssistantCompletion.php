<?php

namespace App\Services\AI;

final readonly class AssistantCompletion
{
    /** @param list<AssistantToolCall> $toolCalls
     * @param  array<string, mixed>  $providerData
     */
    public function __construct(
        public ?string $content,
        public array $toolCalls = [],
        public array $providerData = [],
    ) {}
}
