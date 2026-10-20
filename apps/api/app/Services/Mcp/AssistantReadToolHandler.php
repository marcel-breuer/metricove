<?php

namespace App\Services\Mcp;

use App\Exceptions\InvalidAssistantToolCall;
use App\Exceptions\ModuleDisabled;
use App\Models\McpAccessToken;
use App\Models\User;
use App\Services\AI\AssistantToolRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Mcp\Exception\ToolCallException;
use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\ToolHandlerInterface;

class AssistantReadToolHandler implements ToolHandlerInterface
{
    public function __construct(
        private readonly AssistantToolRegistry $registry,
        private readonly McpAccessToken $token,
        private readonly string $scope,
        private readonly string $toolName,
        private readonly McpAccessService $access,
    ) {}

    public function execute(array $arguments, ClientGateway $gateway): mixed
    {
        try {
            return $this->access->withAuthorizedToken(
                $this->token,
                $this->scope,
                fn (User $actor): array => $this->registry->execute($actor, $this->toolName, $arguments),
            );
        } catch (AuthorizationException|InvalidAssistantToolCall|ModuleDisabled|ModelNotFoundException) {
            throw new ToolCallException('The requested Metricove data tool is unavailable.');
        }
    }
}
