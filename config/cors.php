<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'login', 'logout', 'user', 'register', 'verify-email/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => explode(',', env('FRONTEND_URL')),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];
