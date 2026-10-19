<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json(['service' => 'metricove-api', 'status' => 'ok']);
})->name('home');

Route::get('/password/reset', static fn () => response()->noContent())
    ->name('password.reset');

Route::get('/invitations/accept', static fn () => response()->noContent())
    ->name('invitation.accept');
