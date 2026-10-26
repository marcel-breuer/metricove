<?php

namespace App\Http\Middleware;

use App\Services\NetworkAccess\ClientIpResolver;
use App\Services\NetworkAccess\NetworkAccessConfiguration;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureNetworkAccess
{
    public function __construct(
        private readonly ClientIpResolver $clientIpResolver,
        private readonly NetworkAccessConfiguration $configuration,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('health') || $this->configuration->allows($this->clientIpResolver->resolve($request))) {
            return $next($request);
        }

        return response()->json([
            'message' => 'This address is not allowed to access this installation.',
            'reason' => 'ip_not_allowed',
        ], 403);
    }
}
