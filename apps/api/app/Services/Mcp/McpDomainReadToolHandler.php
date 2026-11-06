<?php

namespace App\Services\Mcp;

use App\Exceptions\ModuleDisabled;
use App\Models\McpAccessToken;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Mcp\Exception\ToolCallException;
use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\ToolHandlerInterface;

class McpDomainReadToolHandler implements ToolHandlerInterface
{
    /** @param list<string> $scopes */
    public function __construct(
        private readonly McpDomainReadWorkflowRegistry $registry,
        private readonly McpAccessService $access,
        private readonly McpAccessToken $token,
        private readonly string $name,
        private readonly array $scopes,
    ) {}

    public function execute(array $arguments, ClientGateway $gateway): mixed
    {
        try {
            return $this->access->withAuthorizedToken(
                $this->token,
                $this->scopes[0],
                function ($actor) use ($arguments): array {
                    foreach (array_slice($this->scopes, 1) as $scope) {
                        $this->access->assertTokenAllows($this->token, $scope);
                    }

                    return $this->registry->execute($this->name, $actor, $arguments);
                },
            );
        } catch (AuthorizationException|ModuleDisabled|ModelNotFoundException|ValidationException) {
            throw new ToolCallException('The requested Metricove data is unavailable.');
        }
    }
}
