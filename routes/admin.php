<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ViewController;


Route::middleware("auth:sanctum")->group(function (){
   Route::controller(ViewController::class)->group(function () {
    Route::get("/sys-info");
   });
<<<<<<< HEAD
});
=======
});



>>>>>>> 5a8861e (Jush)
