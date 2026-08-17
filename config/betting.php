<?php

return [
    'provider' => env('BETTING_PROVIDER', 'vtpass'),
    'category_identifier' => env('BETTING_CATEGORY_IDENTIFIER', 'other-services'),
    'connect_timeout' => (int) env('BETTING_CONNECT_TIMEOUT', 5),
    'timeout' => (int) env('BETTING_TIMEOUT', 20),
];
