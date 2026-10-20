<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\McpAccessToken;
use App\Services\AI\AssistantToolRegistry;
use App\Services\Mcp\AssistantReadToolHandler;
use App\Services\Mcp\McpAccessService;
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
            ->setServerInfo('Metricove', '0.1.0', 'Permission-scoped household data tools with human-approved writes.');

        foreach ($registry->definitionsFor($actor) as $definition) {
            $function = $definition['function'] ?? [];
            $name = $function['name'] ?? null;
            if (! is_string($name)) {
                continue;
            }
            $module = match ($name) {
                'summarize_finance_month' => 'finance',
                'summarize_visible_utility_readings' => 'utilities',
                'summarize_visible_vehicle_odometer_readings' => 'vehicles',
                'summarize_visible_tracker_entries' => 'tracking',
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
