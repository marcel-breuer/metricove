<?php

namespace App\Contracts;

use App\Models\User;

interface AssistantTool
{
    public function name(): string;

    public function moduleKey(): string;

    /** @return array<string, mixed> OpenAI-compatible, JSON-schema tool definition. */
    public function definition(): array;

    /** @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function execute(User $actor, array $arguments): array;
}
