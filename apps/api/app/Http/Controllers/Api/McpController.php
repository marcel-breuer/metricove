<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\McpAccessToken;
use App\Services\AI\AssistantToolRegistry;
use App\Services\Import\ImportService;
use App\Services\Mcp\AssistantReadToolHandler;
use App\Services\Mcp\ImportUtilityReadingsToolHandler;
use App\Services\Mcp\McpAccessService;
use App\Services\Mcp\McpApiReference;
use App\Services\Mcp\McpDomainReadToolHandler;
use App\Services\Mcp\McpDomainReadWorkflowRegistry;
use App\Services\Mcp\McpWriteCompleteToolHandler;
use App\Services\Mcp\McpWriteToolHandler;
use App\Services\Mcp\McpWriteWorkflowRegistry;
use App\Services\Mcp\McpWriteWorkflowService;
use App\Services\Mcp\UtilityReadingCompleteToolHandler;
use App\Services\Mcp\UtilityReadingMcpService;
use App\Services\Mcp\UtilityReadingPreviewToolHandler;
use Illuminate\Http\Request;
use Mcp\Schema\Tool as McpToolSchema;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
use Mcp\Server\Authorization\AccessToken;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\StatelessHttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Response;

class McpController extends Controller
{
    public function __invoke(
        Request $request,
        AssistantToolRegistry $registry,
        UtilityReadingMcpService $actions,
        McpAccessService $access,
        McpWriteWorkflowRegistry $writeRegistry,
        McpWriteWorkflowService $writeWorkflows,
        ImportService $imports,
        McpDomainReadWorkflowRegistry $domainReadRegistry,
        McpApiReference $apiReference,
    ): Response {
        $psrFactory = new Psr17Factory;
        $requestFactory = new PsrHttpFactory($psrFactory, $psrFactory, $psrFactory, $psrFactory);
        $appUrl = parse_url((string) config('app.url')) ?: [];
        $host = is_string($appUrl['host'] ?? null) ? strtolower($appUrl['host']) : 'localhost';
        $origin = (is_string($appUrl['scheme'] ?? null) ? $appUrl['scheme'] : 'http').'://'.$host
            .(isset($appUrl['port']) ? ':'.$appUrl['port'] : '');
        $allowedOrigins = array_values(array_unique(array_map(
            fn (string $allowedOrigin): string => rtrim(strtolower($allowedOrigin), '/'),
            array_filter([$origin, ...config('mcp.allowed_origins', [])], 'is_string'),
        )));
        $middleware = [
            new CorsMiddleware($allowedOrigins, ['POST']),
            new DnsRebindingProtectionMiddleware(array_values(array_unique([$host, 'localhost', '127.0.0.1', '[::1]'])), $allowedOrigins),
        ];

        if ($request->isMethod('OPTIONS')) {
            $emptyServer = Server::builder()->setServerInfo('Metricove', '0.1.0')->buildStateless();
            $transport = new StatelessHttpTransport($emptyServer, $psrFactory, $psrFactory, middleware: $middleware);

            return $this->httpResponse($transport->handle($requestFactory->createRequest($request)));
        }

        $token = $request->attributes->get(McpAccessToken::class);
        abort_unless($token instanceof McpAccessToken, 401);
        $actor = $token->user()->first();
        abort_unless($actor !== null && $actor->is_active, 401);
        $scopes = array_values(array_intersect($token->scopes ?? [], $access->allowedScopes($actor)));

        $server = Server::builder()
            ->setServerInfo(
                'Metricove',
                (string) config('scramble.info.version', '0.1.0'),
                'Permission-scoped household data tools with human-approved writes. Call tools/list to discover the current tool names, descriptions and input schemas available to this token. Tool availability follows current permissions and module settings.',
            );

        $server->addResource(
            fn () => $apiReference->read(),
            McpApiReference::URI,
            'metricove_http_api',
            'Metricove HTTP API reference',
            'Current OpenAPI contract generated from Laravel routes and validation. It documents the session-authenticated HTTP API; this MCP bearer token authorizes only the registered MCP tools exposed to it.',
            'application/json',
        );

        foreach ($registry->definitionsFor($actor) as $definition) {
            $function = $definition['function'] ?? [];
            $name = $function['name'] ?? null;
            if (! is_string($name)) {
                continue;
            }
            $module = match ($name) {
                'summarize_finance_month', 'list_visible_financial_records' => 'finance',
                'summarize_visible_utility_readings' => 'utilities',
                'summarize_visible_vehicle_odometer_readings' => 'vehicles',
                'summarize_visible_tracker_entries' => 'tracking',
                'list_visible_recipes' => 'recipes',
                'show_visible_meal_plans' => 'meal_planning',
                'list_visible_pantry_items' => 'pantry',
                'list_visible_shopping_lists' => 'shopping',
                'show_visible_analytics' => 'analytics',
                'list_visible_tasks' => 'tasks',
                default => null,
            };
            if ($module === null || ! in_array($module.':view', $scopes, true)) {
                continue;
            }

            $tool = $function;
            $parameters = $tool['parameters'] ?? ['type' => 'object', 'properties' => new \stdClass];
            $server->add(
                new McpToolSchema(
                    name: $name,
                    title: null,
                    inputSchema: $parameters,
                    description: is_string($tool['description'] ?? null) ? $tool['description'] : null,
                    annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
                ),
                new AssistantReadToolHandler($registry, $token, $module.':view', $name, $access),
            );
        }

        foreach ($domainReadRegistry->all() as $read) {
            if (array_diff($read['scopes'], $scopes) !== []) {
                continue;
            }

            $server->add(
                new McpToolSchema(
                    name: $read['name'],
                    title: $read['title'],
                    inputSchema: $read['inputSchema'],
                    description: $read['description'],
                    annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
                ),
                new McpDomainReadToolHandler($domainReadRegistry, $access, $token, $read['name'], $read['scopes']),
            );
        }

        if (in_array('import:view', $scopes, true) && in_array('utilities:view', $scopes, true)) {
            $server->add(
                new McpToolSchema(
                    name: 'list_exportable_utility_readings',
                    title: 'List exportable utility readings',
                    inputSchema: [
                        'type' => 'object',
                        'properties' => [
                            'meter_id' => ['type' => 'integer', 'minimum' => 1],
                            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
                        ],
                        'required' => ['meter_id'],
                        'additionalProperties' => false,
                    ],
                    description: 'List up to 50 authorized utility reading rows in export order. Both Import and Utilities read access are required.',
                    annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
                ),
                new ImportUtilityReadingsToolHandler($access, $imports, $token),
            );
        }

        if (in_array('utilities:create', $scopes, true)) {
            $server->add(
                new McpToolSchema(
                    name: 'preview_utility_reading',
                    title: 'Preview a utility reading',
                    inputSchema: [
                        'type' => 'object',
                        'properties' => [
                            'meter_id' => ['type' => 'integer'],
                            'reading_date' => ['type' => 'string', 'format' => 'date'],
                            'value' => ['type' => 'string', 'pattern' => '^(0|[1-9][0-9]{0,13})(\\.[0-9]{1,6})?$'],
                            'note' => ['type' => 'string', 'maxLength' => 2000],
                        ],
                        'required' => ['meter_id', 'reading_date', 'value'],
                        'additionalProperties' => false,
                    ],
                    description: 'Prepare a utility reading for the account owner to review in Metricove. This tool does not write the reading.',
                    annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
                ),
                new UtilityReadingPreviewToolHandler($actions, $token),
            );
            $server->add(
                new McpToolSchema(
                    name: 'complete_approved_utility_reading',
                    title: 'Complete an approved utility reading',
                    inputSchema: [
                        'type' => 'object',
                        'properties' => [
                            'preview_id' => ['type' => 'string', 'format' => 'uuid'],
                            'idempotency_key' => ['type' => 'string', 'format' => 'uuid'],
                        ],
                        'required' => ['preview_id', 'idempotency_key'],
                        'additionalProperties' => false,
                    ],
                    description: 'Save a utility reading only after its owner approved the preview in Metricove. Reuse the same idempotency key for a retry.',
                    annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false),
                ),
                new UtilityReadingCompleteToolHandler($actions, $token),
            );
        }

        $hasWriteTool = false;
        foreach ($writeRegistry->all() as $workflow) {
            $candidateScopes = array_map(fn (string $capability): string => $workflow['module'].':'.$capability, $workflow['capabilities']);
            $hasScope = array_intersect($candidateScopes, $scopes) !== [];
            if (! $hasScope || array_diff($workflow['required_scopes'], $scopes) !== []) {
                continue;
            }
            $hasWriteTool = true;
            $name = substr($workflow['name'], strlen('preview_'));
            $server->add(
                new McpToolSchema(
                    name: $workflow['name'],
                    title: $workflow['title'],
                    inputSchema: $workflow['inputSchema'],
                    description: $workflow['description'],
                    annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
                ),
                new McpWriteToolHandler($writeWorkflows, $token, $name),
            );
        }

        if ($hasWriteTool) {
            $server->add(
                new McpToolSchema(
                    name: 'complete_approved_mcp_change',
                    title: 'Complete an approved Metricove change',
                    inputSchema: [
                        'type' => 'object',
                        'properties' => [
                            'preview_id' => ['type' => 'string', 'format' => 'uuid'],
                            'idempotency_key' => ['type' => 'string', 'format' => 'uuid'],
                        ],
                        'required' => ['preview_id', 'idempotency_key'],
                        'additionalProperties' => false,
                    ],
                    description: 'Complete a pending change only after its owner approved it in Metricove. Reuse the same idempotency key for a retry.',
                    annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false),
                ),
                new McpWriteCompleteToolHandler($writeWorkflows, $token),
            );
        }

        $psrRequest = $requestFactory->createRequest($request)
            ->withAttribute(AccessToken::class, $request->attributes->get(AccessToken::class));
        $transport = new StatelessHttpTransport(
            $server->buildStateless(),
            $psrFactory,
            $psrFactory,
            middleware: $middleware,
        );
        $psrResponse = $transport->handle($psrRequest);

        return $this->httpResponse($psrResponse);
    }

    private function httpResponse(ResponseInterface $response): Response
    {
        $httpResponse = (new HttpFoundationFactory)->createResponse($response);
        $httpResponse->headers->set('Cache-Control', 'no-store');
        $httpResponse->headers->set('X-Content-Type-Options', 'nosniff');

        return $httpResponse;
    }
}
