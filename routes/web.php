<?php

use Illuminate\Support\Facades\Route;



Route::middleware("auth:sanctum")->group(function (){
    Route::prefix("service")->group(function() {
        Route::post("/airtime", );
    });
});

require __DIR__.'/auth.php';
require __DIR__.'/admin.php';

