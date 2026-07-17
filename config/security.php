<?php

return [
    'web_idle_minutes' => (int) env('SESSION_IDLE_TIMEOUT', 30),
    'web_absolute_hours' => (int) env('SESSION_ABSOLUTE_TIMEOUT', 12),
    'remember_days' => (int) env('REMEMBER_SESSION_DAYS', 30),
    'access_token_minutes' => (int) env('ACCESS_TOKEN_TTL', 15),
    'refresh_token_days' => (int) env('REFRESH_TOKEN_TTL_DAYS', 30),
    'recent_auth_minutes' => (int) env('RECENT_AUTH_TTL', 10),
    'activity_write_seconds' => (int) env('SESSION_ACTIVITY_WRITE_INTERVAL', 60),
];
