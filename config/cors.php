<?php

return [
    'paths' => explode(',', env('CORS_PATHS', 'api/*,sanctum/csrf-cookie')),
    'allowed_methods' => ['*'],
    'allowed_origins' => explode(',', env('CORS_ALLOWED_ORIGINS', '*')),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => explode(',', env('CORS_EXPOSED_HEADERS', '')),
    'max_age' => (int) env('CORS_MAX_AGE', 0),
    'supports_credentials' => (bool) env('CORS_SUPPORTS_CREDENTIALS', false),
];


