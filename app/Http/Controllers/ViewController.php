<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\General;

class ViewController extends Controller
{
    //
    function system_info(){
        return response()->json([
            "data" => General::first()->toJson(),
            "message" => "success",
            "success" => true
        ]);
    }
}
