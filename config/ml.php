<?php

return [
    'server_url' => env('ML_SERVER_URL', 'http://localhost:8001'),
    'timeout' => env('ML_SERVER_TIMEOUT', 10),
    'cache_ttl' => env('ML_CACHE_TTL', 86400),
];


