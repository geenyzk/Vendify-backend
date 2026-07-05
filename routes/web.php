<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/', function () {
    return view('scribe.index');
});

Route::get('/docs.postman', function () {
    abort_unless(Storage::disk('local')->exists('scribe/collection.json'), 404);

    return Response::download(storage_path('app/scribe/collection.json'));
})->name('scribe.postman');

Route::get('/docs.openapi', function () {
    abort_unless(Storage::disk('local')->exists('scribe/openapi.yaml'), 404);

    return Response::file(storage_path('app/scribe/openapi.yaml'), [
        'Content-Type' => 'application/yaml',
    ]);
})->name('scribe.openapi');

Route::get('/cache', function () {
    return Cache::flush();
});

require __DIR__.'/auth.php';
require __DIR__.'/admin.php';
