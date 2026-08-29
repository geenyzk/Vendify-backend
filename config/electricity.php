<?php

return [
    'sandbox_enabled' => filter_var(env('ELECTRICITY_SANDBOX_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'sandbox_allowed_emails' => array_values(array_filter(array_map(
        fn (string $email) => strtolower(trim($email)),
        explode(',', (string) env('ELECTRICITY_SANDBOX_ALLOWED_EMAILS', '')),
    ))),
];
