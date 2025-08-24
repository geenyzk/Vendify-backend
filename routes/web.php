<?php

use Illuminate\Support\Facades\Route;
<<<<<<< HEAD



Route::middleware("auth:sanctum")->group(function (){
    Route::prefix("service")->group(function() {
        Route::post("/airtime", );
    });
});

require __DIR__.'/auth.php';
require __DIR__.'/admin.php';

=======
use Illuminate\Support\Facades\Cache;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {



    return view('scribe.index', [

    ]);
});

Route::get('/cache', function () {
    return
        Cache::flush();
});
>>>>>>> 5a8861e (Jush)
