<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_filter(explode(',', env('FRONTEND_ORIGINS', env('APP_URL', 'http://localhost:8000')))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Accept', 'Content-Type', 'X-XSRF-TOKEN', 'X-Requested-With', 'X-Cart-Token', 'X-Order-Token', 'Idempotency-Key'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];
