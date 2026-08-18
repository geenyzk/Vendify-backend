<?php

return [
    'provider' => env('BETTING_PROVIDER', 'vtu_ng'),
    'connect_timeout' => (int) env('BETTING_CONNECT_TIMEOUT', 5),
    'timeout' => (int) env('BETTING_TIMEOUT', 20),
];
