<?php

namespace App\Http\Middleware;

use App\Exceptions\ModuleDisabled;
use App\Exceptions\ModuleNotFound;
use App\Models\User;
use App\Services\Modules\ModuleAccessService;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureModuleAccess
{
    public function handle(
        Request $request,
        Closure $next,
        string $moduleKey,
        string $capability,
    ): Response {
        $actor = $request->user('web');

        if (! $actor instanceof User) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        try {
            app(ModuleAccessService::class)->authorize($actor, $moduleKey, $capability);
        } catch (ModuleDisabled|ModuleNotFound) {
            return response()->json(['message' => 'This module is unavailable.'], 404);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'This module operation is not permitted.'], 403);
        }

        return $next($request);
    }
}
