<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'sanctum/csrf-token', 'login', 'logout', 'user', 'register', 'verify-email/*'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', env('FRONTEND_URL', ''))))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'Origin', 'X-Client-Platform', 'X-Device-Id', 'X-Device-Name', 'X-Country-Code', 'X-CSRF-TOKEN', 'X-Requested-With', 'X-XSRF-TOKEN'],
    'exposed_headers' => ['X-Cache', 'Server-Timing', 'X-Session-Expires-At', 'X-Auth-Session-Id'],
    'max_age' => (int) env('CORS_MAX_AGE', 3600),
    'supports_credentials' => true,
];
