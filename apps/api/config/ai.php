<?php

return [
    'provider' => env('AI_PROVIDER', 'disabled'),
    'base_url' => env('AI_BASE_URL'),
    'model' => env('AI_MODEL'),
    'api_key' => env('AI_API_KEY'),
    'external_provider' => env('AI_EXTERNAL_PROVIDER', false),
    'timeout_seconds' => 20,
];
