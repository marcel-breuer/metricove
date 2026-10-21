<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\McpAccessToken;
use App\Models\User;
use App\Services\Mcp\McpAccessService;
use App\Services\Mcp\UtilityReadingMcpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class McpAccessController extends Controller
{
    public function index(Request $request, McpAccessService $access, UtilityReadingMcpService $actions): JsonResponse
    {
        $user = $this->actor($request);
        $tokens = McpAccessToken::query()
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (McpAccessToken $token): array => $access->presentToken($token))
            ->all();

        return response()->json([
            'endpoint' => url('/api/mcp'),
            'allowed_scopes' => $access->allowedScopes($user),
            'tokens' => $tokens,
            'actions' => $actions->pendingFor($user),
            'authorization' => 'Metricove user-managed bearer tokens; OAuth authorization-server discovery is not provided.',
        ], 200, ['Cache-Control' => 'no-store']);
    }

    public function store(Request $request, McpAccessService $access): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'scopes' => ['required', 'array', 'min:1', 'max:5'],
            'scopes.*' => ['required', 'string', 'distinct'],
            'expires_in_days' => ['present', 'nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $created = $access->createToken(
            $this->actor($request),
            $validated['name'],
            $validated['scopes'],
            $validated['expires_in_days'] === null ? null : (int) $validated['expires_in_days'],
        );

        return response()->json([
            'token' => $access->presentToken($created['token']),
            'secret' => $created['secret'],
            'message' => 'Copy this token now. It will not be shown again.',
        ], 201, ['Cache-Control' => 'no-store']);
    }

    public function destroy(Request $request, int $token, McpAccessService $access): JsonResponse
    {
        $access->revokeToken($this->actor($request), $token);

        return response()->json(['message' => 'MCP token revoked.'], 200, ['Cache-Control' => 'no-store']);
    }

    public function approve(Request $request, string $preview, UtilityReadingMcpService $actions): JsonResponse
    {
        $actions->approve($this->actor($request), $preview);

        return response()->json(['message' => 'The MCP action is approved.'], 200, ['Cache-Control' => 'no-store']);
    }

    public function reject(Request $request, string $preview, UtilityReadingMcpService $actions): JsonResponse
    {
        $actions->reject($this->actor($request), $preview);

        return response()->json(['message' => 'The MCP action was rejected.'], 200, ['Cache-Control' => 'no-store']);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user('web');
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
