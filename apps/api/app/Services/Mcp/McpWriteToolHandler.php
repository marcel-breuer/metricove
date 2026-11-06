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

class McpWriteToolHandler implements ToolHandlerInterface
{
    public function __construct(
        private readonly McpWriteWorkflowService $workflows,
        private readonly McpAccessToken $token,
        private readonly string $workflowName,
    ) {}

    public function execute(array $arguments, ClientGateway $gateway): mixed
    {
        try {
            return $this->workflows->preview($this->token, $this->workflowName, $arguments);
        } catch (AuthorizationException|ModuleDisabled|ModelNotFoundException|ValidationException) {
            throw new ToolCallException('The requested change is unavailable. Check current Metricove permissions and the supplied details.');
        }
    }
}
