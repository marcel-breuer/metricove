<?php

namespace App\Http\Middleware;

use App\Models\McpAccessToken;
use App\Models\User;
use App\Services\Mcp\McpAccessService;
use Closure;
use Illuminate\Http\Request;
use Mcp\Server\Authorization\AccessToken;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateMcpToken
{
    public function __construct(private readonly McpAccessService $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('OPTIONS')) {
            return $next($request);
        }

        $authorization = $request->header('Authorization', '');
        if (preg_match('/\ABearer (mtv_[a-f0-9]{64})\z/i', $authorization, $matches) !== 1) {
            return $this->unauthorized();
        }

        $token = McpAccessToken::query()->where('token_hash', hash('sha256', $matches[1]))->first();
        if (! $token instanceof McpAccessToken || ! $token->isUsable()) {
            return $this->unauthorized();
        }

        $actor = User::query()->whereKey($token->user_id)->where('is_active', true)->first();
        if (! $actor instanceof User) {
            return $this->unauthorized();
        }

        $token->forceFill(['last_used_at' => now()])->saveQuietly();
        $request->attributes->set(McpAccessToken::class, $token);
        $request->attributes->set(AccessToken::class, $this->access->sdkAccessToken($token, $actor));

        return $next($request);
    }

    private function unauthorized(): Response
    {
        return response()->json(['message' => 'A valid Metricove MCP bearer token is required.'], 401, [
            'WWW-Authenticate' => 'Bearer',
            'Cache-Control' => 'no-store',
        ]);
    }
}
