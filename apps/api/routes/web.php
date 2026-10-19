<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json(['service' => 'metricove-api', 'status' => 'ok']);
})->name('home');
