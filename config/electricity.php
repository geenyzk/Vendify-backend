<?php

return [
    // The production check is deliberately duplicated in the provider so a
    // cached/misconfigured config value cannot activate sandbox in production.
    'sandbox_enabled' => filter_var(env('ELECTRICITY_SANDBOX_ENABLED', false), FILTER_VALIDATE_BOOLEAN)
        && env('APP_ENV', 'production') !== 'production',
];
