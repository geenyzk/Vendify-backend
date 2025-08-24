<?php

namespace App\Http\Controllers;

use App\Class\Payment\Payment;
use App\Class\Vendor\VendorFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    //

    function handle(Request $request,string $type, string $identifier){

        Log::info($request->all());
        try {
            //code...
            switch ($type) {
                case 'payment':
                    return Payment::webhook($request, $identifier) ;

                case 'vendor':
                default:
                    return VendorFactory::webhook($request, $identifier) ;
            }
        } catch (\Throwable $th) {
            //throw $th;
            $this->fail([], "Unauthorized", 401);
        }


    }
}
