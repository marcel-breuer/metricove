<?php

use App\Http\Middleware\AuthenticateMcpToken;
use App\Http\Middleware\EnsureActiveAdmin;
use App\Http\Middleware\EnsureActiveMember;
use App\Http\Middleware\EnsureModuleAccess;
use App\Http\Middleware\EnsureModuleEnabled;
use App\Http\Middleware\EnsureNetworkAccess;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prependToGroup('api', EnsureNetworkAccess::class);
        $middleware->alias([
            'active-admin' => EnsureActiveAdmin::class,
            'active-member' => EnsureActiveMember::class,
            'module.enabled' => EnsureModuleEnabled::class,
            'module.access' => EnsureModuleAccess::class,
            'mcp.auth' => AuthenticateMcpToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
