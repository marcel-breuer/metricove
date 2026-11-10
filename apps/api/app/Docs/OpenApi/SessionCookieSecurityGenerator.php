<?php

namespace App\Docs\OpenApi;

use Knuckles\Camel\Output\OutputEndpointData;
use Knuckles\Scribe\Writing\OpenApiSpecGenerators\OpenApiGenerator;

class SessionCookieSecurityGenerator extends OpenApiGenerator
{
    public function root(array $root, array $groupedEndpoints): array
    {
        $root['components']['securitySchemes'] = [
            'sessionCookie' => [
                'type' => 'apiKey',
                'in' => 'cookie',
                'name' => config('session.cookie'),
                'description' => 'Encrypted Metricove browser session cookie. State-changing requests also require X-XSRF-TOKEN.',
            ],
        ];
        $root['security'] = [['sessionCookie' => []]];

        return $root;
    }

    public function pathItem(array $pathItem, array $groupedEndpoints, OutputEndpointData $endpoint): array
    {
        $pathItem['security'] = $endpoint->metadata->authenticated
            ? [['sessionCookie' => []]]
            : [];

        return $pathItem;
    }
}
