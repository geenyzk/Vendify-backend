<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('scribe.index');
});

Route::get('/cache', function () {
    return Cache::flush();
});

require __DIR__.'/auth.php';
require __DIR__.'/admin.php';
