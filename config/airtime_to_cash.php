<?php

return [
    // Independent gates: the UI/configuration switch cannot authorize real HTTP.
    'provider_mode_enabled' => env('AIRTIME_TO_CASH_PROVIDER_MODE_ENABLED', false),
    'live_calls_enabled' => env('AIRTIME_TO_CASH_LIVE_CALLS_ENABLED', false),
    'session_minutes' => 10, // Local safety window, not a claimed provider TTL.
    'providers' => [
        'airtime_to_cash_automation' => [
            'name' => 'AirtimeToCash Automation',
            'base_url' => env('AIRTIME_TO_CASH_AUTOMATION_BASE_URL', 'https://automation.airtimetocash.com'),
            'token' => env('AIRTIME_TO_CASH_AUTOMATION_TOKEN'),
            'enabled' => env('AIRTIME_TO_CASH_AUTOMATION_ENABLED', false),
            'priority' => 1,
            'allowed_host' => 'automation.airtimetocash.com',
            'credential_label' => 'API token',
        ],
        '2fast' => [
            'name' => '2FAST',
            'base_url' => env('AIRTIME_TO_CASH_2FAST_BASE_URL', 'https://2fast.com.ng'),
            'token' => env('AIRTIME_TO_CASH_2FAST_TOKEN'),
            'enabled' => env('AIRTIME_TO_CASH_2FAST_ENABLED', false),
            'priority' => 2,
            'allowed_host' => '2fast.com.ng',
            'credential_label' => 'API key',
        ],
    ],
];
