<?php

namespace App\Docs\OpenApi;

use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Knuckles\Camel\Output\OutputEndpointData;
use Knuckles\Scribe\Writing\OpenApiSpecGenerators\OpenApiGenerator;

/**
 * Turns Scribe's raw extraction into the Metricove HTTP API contract: module tags, summaries,
 * the uniform error envelope, and machine-readable module gating taken from route middleware.
 */
class ApiContractGenerator extends OpenApiGenerator
{
    private const TAGS = [
        'admin' => 'Administration',
        'ai' => 'AI assistant',
        'analytics' => 'Analytics',
        'auth' => 'Authentication',
        'health' => 'Health',
        'invitations' => 'Setup and invitations',
        'meal-planning' => 'Meal planning',
        'modules' => 'Modules',
        'notes' => 'Notes',
        'pantry' => 'Pantry',
        'profile' => 'Profile and MCP access',
        'receipts' => 'Receipts',
        'recipes' => 'Recipes',
        'reminders' => 'Reminders',
        'setup' => 'Setup and invitations',
        'shopping' => 'Shopping',
        'tasks' => 'Tasks',
        'tracking' => 'Tracking',
        'utilities' => 'Utilities',
        'vehicles' => 'Vehicles',
    ];

    private const VERBS = ['GET' => 'Get', 'POST' => 'Create', 'PUT' => 'Replace', 'PATCH' => 'Update', 'DELETE' => 'Delete'];

    public function root(array $root, array $groupedEndpoints): array
    {
        $root['info']['title'] = 'Metricove HTTP API';
        $root['info']['description'] = 'First-party REST API of a Metricove installation. Authentication uses the browser session cookie; state-changing requests also need the X-XSRF-TOKEN header. '
            .'Errors use a JSON envelope: `{"message": string}` and, for validation failures (422), `{"message": string, "errors": {field: [string]}}`. '
            .'Optional modules answer 404 when disabled and 403 without the member capability. AI agents use the MCP endpoint instead (see docs/mcp).';
        $root['tags'] = collect(array_unique(array_values(self::TAGS)))->sort()->values()
            ->map(fn (string $name): array => ['name' => $name])->all();
        $error = ['type' => 'object', 'required' => ['message'], 'properties' => ['message' => ['type' => 'string']]];
        $root['components']['schemas'] = [
            'Error' => $error,
            'ValidationError' => [
                'type' => 'object', 'required' => ['message', 'errors'],
                'properties' => [
                    'message' => ['type' => 'string'],
                    'errors' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']]],
                ],
            ],
        ];
        $reference = fn (string $schema): array => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/'.$schema]]];
        $root['components']['responses'] = [
            'Unauthenticated' => ['description' => 'No valid session.', 'content' => $reference('Error')],
            'Forbidden' => ['description' => 'The member lacks the required capability, role or resource access.', 'content' => $reference('Error')],
            'NotFound' => ['description' => 'The resource does not exist, is not visible to the member, or its module is disabled.', 'content' => $reference('Error')],
            'Conflict' => ['description' => 'The record changed since it was read (optimistic version check).', 'content' => $reference('Error')],
            'ValidationError' => ['description' => 'The request failed validation.', 'content' => $reference('ValidationError')],
            'TooManyRequests' => ['description' => 'Rate limit exceeded.', 'content' => $reference('Error')],
        ];

        return $root;
    }

    public function pathItem(array $pathItem, array $groupedEndpoints, OutputEndpointData $endpoint): array
    {
        $method = strtoupper($endpoint->httpMethods[0] ?? 'GET');
        $route = $this->route($endpoint, $method);
        $middleware = $route?->gatherMiddleware() ?? [];
        $segments = array_values(array_filter(explode('/', trim($endpoint->uri, '/'))));
        $static = array_values(array_filter(array_slice($segments, 1), fn (string $part): bool => ! str_starts_with($part, '{')));

        $pathItem['tags'] = [self::TAGS[$segments[1] ?? ''] ?? 'Core'];
        $pathItem['summary'] = (self::VERBS[$method] ?? $method).' '.Str::of(implode(' ', $static))->replace(['-', '.'], ' ')->lower();
        $pathItem['parameters'] = array_values(array_filter(
            $pathItem['parameters'] ?? [],
            fn (array $parameter): bool => ! (($parameter['in'] ?? null) === 'header' && ($parameter['name'] ?? null) === 'Cookie'),
        ));

        $responses = ['200' => ['description' => 'Successful response.']];
        $authenticated = collect($middleware)->contains(fn (string $name): bool => str_starts_with($name, 'auth:'));
        if ($authenticated) {
            $responses['401'] = ['$ref' => '#/components/responses/Unauthenticated'];
        }
        $gated = collect($middleware)->contains(fn (string $name): bool => str_starts_with($name, 'module.') || $name === 'active-admin');
        if ($gated || $authenticated) {
            $responses['403'] = ['$ref' => '#/components/responses/Forbidden'];
        }
        if ($segments !== [] && (collect($endpoint->urlParameters)->isNotEmpty() || collect($middleware)->contains(fn (string $name): bool => str_starts_with($name, 'module.')))) {
            $responses['404'] = ['$ref' => '#/components/responses/NotFound'];
        }
        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $responses['409'] = ['$ref' => '#/components/responses/Conflict'];
            $responses['422'] = ['$ref' => '#/components/responses/ValidationError'];
        }
        if (collect($middleware)->contains(fn (string $name): bool => str_starts_with($name, 'throttle:'))) {
            $responses['429'] = ['$ref' => '#/components/responses/TooManyRequests'];
        }
        ksort($responses);
        $pathItem['responses'] = $responses;

        foreach ($middleware as $name) {
            if (preg_match('/^module\.access:([a-z_]+),([a-z]+)/', $name, $matches) === 1) {
                $pathItem['x-metricove-module'] = ['key' => $matches[1], 'capability' => $matches[2]];
            } elseif (preg_match('/^module\.enabled:([a-z_]+)/', $name, $matches) === 1 && ! isset($pathItem['x-metricove-module'])) {
                $pathItem['x-metricove-module'] = ['key' => $matches[1], 'capability' => 'resource-checked'];
            }
            if ($name === 'active-admin') {
                $pathItem['x-metricove-admin-only'] = true;
            }
        }

        return $pathItem;
    }

    private function route(OutputEndpointData $endpoint, string $method): ?Route
    {
        $uri = OpenApiContract::canonicalPath($endpoint->uri);
        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            if (OpenApiContract::canonicalPath($route->uri()) === $uri && in_array($method, $route->methods(), true)) {
                return $route;
            }
        }

        return null;
    }
}
