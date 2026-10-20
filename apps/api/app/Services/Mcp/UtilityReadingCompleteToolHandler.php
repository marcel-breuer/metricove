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

class UtilityReadingCompleteToolHandler implements ToolHandlerInterface
{
    public function __construct(
        private readonly UtilityReadingMcpService $actions,
        private readonly McpAccessToken $token,
    ) {}

    public function execute(array $arguments, ClientGateway $gateway): mixed
    {
        if (array_diff(array_keys($arguments), ['preview_id', 'idempotency_key']) !== []
            || ! is_string($arguments['preview_id'] ?? null)
            || ! is_string($arguments['idempotency_key'] ?? null)) {
            throw ValidationException::withMessages(['arguments' => 'The approved utility reading action arguments are invalid.']);
        }

        try {
            return $this->actions->complete($this->token, $arguments['preview_id'], $arguments['idempotency_key']);
        } catch (AuthorizationException|ModuleDisabled|ModelNotFoundException|ValidationException) {
            throw new ToolCallException('The approved utility reading could not be completed. Check its approval, permissions and expiry in Metricove.');
        }
    }
}
