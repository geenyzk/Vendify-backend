<?php

namespace App\Http\Controllers;

use App\Class\Payment\Payment;
use App\Class\Vendor\VendorFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
<<<<<<< HEAD
    //
=======




    /**
     * Handle payment or vendor webhook
     *
     * This handles webhook requests from payment providers or vendors.
     *@group webhook
     * @urlParam type string required Type of webhook (payment or vendor). Example: payment
     * @urlParam identifier string required Identifier for the webhook. Example: flutterwave
     *
     * @unauthenticated
     *
     * @response 200 {
     *   "status": "success",
     *   "message": "Webhook handled"
     * }
     */
    public function handle(Request $request, string $type, string $identifier)

    {
        Log::info($request->all());
>>>>>>> bbdf8dbc93811b942956ea2015f977bbc20327d4

    function handle(Request $request,string $type, string $identifier){

        Log::info($request->all());
        try {
<<<<<<< HEAD
            //code...
=======


    {

        Log::info($request->all());
        try {
            //code...

>>>>>>> bbdf8dbc93811b942956ea2015f977bbc20327d4
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


<<<<<<< HEAD
=======



    }
}
>>>>>>> bbdf8dbc93811b942956ea2015f977bbc20327d4
    }
}
