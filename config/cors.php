<?php

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'docs/*'],

    'allowed_methods' => ['*'],

    //'allowed_origins' => ['http://localhost:8000', 'http://127.0.0.1:8000'],
    'allowed_origins' => ['*'], //allow all origins for development, change for production.

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];

