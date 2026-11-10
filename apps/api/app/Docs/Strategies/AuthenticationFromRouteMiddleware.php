<?php

namespace App\Docs\Strategies;

use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Scribe\Extracting\Strategies\Strategy;

class AuthenticationFromRouteMiddleware extends Strategy
{
    public function __invoke(ExtractedEndpointData $endpointData, array $settings = []): ?array
    {
        if ($endpointData->route === null) {
            return null;
        }

        $authenticated = collect($endpointData->route->gatherMiddleware())
            ->contains(fn (string $middleware): bool => $middleware === 'auth'
                || str_starts_with($middleware, 'auth:'));

        return ['authenticated' => $authenticated];
    }
}
