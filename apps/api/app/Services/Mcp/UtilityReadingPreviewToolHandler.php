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

class UtilityReadingPreviewToolHandler implements ToolHandlerInterface
{
    public function __construct(
        private readonly UtilityReadingMcpService $actions,
        private readonly McpAccessToken $token,
    ) {}

    public function execute(array $arguments, ClientGateway $gateway): mixed
    {
        try {
            return $this->actions->preview($this->token, $arguments);
        } catch (AuthorizationException|ModuleDisabled|ModelNotFoundException|ValidationException) {
            throw new ToolCallException('The utility reading preview is unavailable. Check current Metricove permissions and reading details.');
        }
    }
}
