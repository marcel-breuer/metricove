<?php

use App\Services\Mcp\McpApiReference;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json(['service' => 'metricove-api', 'status' => 'ok']);
})->name('home');

Route::get('/password/reset', static fn () => response()->noContent())
    ->name('password.reset');

Route::get('/invitations/accept', static fn () => response()->noContent())
    ->name('invitation.accept');

Route::get('/docs/api.json', static fn (McpApiReference $reference) => response()->json($reference->document()))
    ->middleware(['auth:web', 'active-member'])
    ->name('api.documentation.json');
