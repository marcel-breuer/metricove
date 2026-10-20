<?php

namespace App\Contracts;

use App\Services\AI\AssistantCompletion;
use App\Services\AI\AssistantCompletionRequest;

interface AssistantProvider
{
    public function isAvailable(): bool;

    public function isExternal(): bool;

    public function label(): string;

    public function complete(AssistantCompletionRequest $request): AssistantCompletion;
}
