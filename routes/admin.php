<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ViewController;


Route::middleware("auth:sanctum")->group(function (){
   Route::controller(ViewController::class)->group(function () {
    Route::get("/sys-info");
   });
<<<<<<< HEAD
=======
<<<<<<< HEAD
});
=======
>>>>>>> bbdf8dbc93811b942956ea2015f977bbc20327d4
});



<<<<<<< HEAD
=======
>>>>>>> 5a8861e (Jush)
>>>>>>> bbdf8dbc93811b942956ea2015f977bbc20327d4
