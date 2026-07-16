<?php

return [
    // Local/staging diagnostic only; never includes credentials or user data.
    'login_profiling' => (bool) env('LOGIN_PROFILING', false),
    'profile_paths' => array_values(array_filter(array_map(
        'trim',
        explode(',', env('PERFORMANCE_PROFILE_PATHS', 'api/login,api/user,api/customer/dashboard,api/welcome-message,api/notifications/unread-count,api/table/networks,api/table/data_plans'))
    ))),
];
