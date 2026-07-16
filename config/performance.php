<?php

return [
    // Local/staging diagnostic only; never includes credentials or user data.
    'login_profiling' => (bool) env('LOGIN_PROFILING', false),
];
