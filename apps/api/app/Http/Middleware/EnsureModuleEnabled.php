<?php

namespace App\Http\Middleware;

use App\Services\Modules\ModuleStateService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureModuleEnabled
{
    public function handle(Request $request, Closure $next, string $moduleKey): Response
    {
        if (! app(ModuleStateService::class)->isEnabled($moduleKey)) {
            return response()->json(['message' => 'This module is unavailable.'], 404);
        }

        return $next($request);
    }
}
