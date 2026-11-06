<?php

namespace App\Services\Mcp;

use App\Exceptions\ModuleDisabled;
use App\Models\McpAccessToken;
use App\Models\User;
use App\Models\UtilityReading;
use App\Services\Import\ImportService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Mcp\Exception\ToolCallException;
use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\ToolHandlerInterface;

class ImportUtilityReadingsToolHandler implements ToolHandlerInterface
{
    public function __construct(
        private readonly McpAccessService $access,
        private readonly ImportService $imports,
        private readonly McpAccessToken $token,
    ) {}

    public function execute(array $arguments, ClientGateway $gateway): mixed
    {
        $meterId = $arguments['meter_id'] ?? null;
        $limit = $arguments['limit'] ?? 50;
        if (array_diff(array_keys($arguments), ['meter_id', 'limit']) !== []
            || ! is_int($meterId)
            || ! is_int($limit)
            || $meterId < 1
            || $limit < 1
            || $limit > 50) {
            throw new ToolCallException('The requested Metricove data tool is unavailable.');
        }

        try {
            return $this->access->withAuthorizedToken($this->token, 'import:view', function (User $actor) use ($meterId, $limit): array {
                $this->access->assertTokenAllows($this->token, 'utilities:view');
                $rows = $this->imports->exportUtilityReadings($actor, $meterId)
                    ->take($limit + 1)
                    ->map(fn (UtilityReading $reading): array => [
                        'reading_date' => CarbonImmutable::parse((string) $reading->reading_date)->toDateString(),
                        'value' => (string) $reading->value,
                        'note' => $reading->note,
                    ])
                    ->values()
                    ->all();
                $truncated = count($rows) > $limit;

                return ['readings' => array_slice($rows, 0, $limit), 'truncated' => $truncated];
            });
        } catch (AuthorizationException|ModuleDisabled|ModelNotFoundException|ValidationException) {
            throw new ToolCallException('The requested Metricove data tool is unavailable.');
        }
    }
}
