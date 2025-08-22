<?php

namespace App\Http\Controllers;

use App\Class\Payment\Payment;
use App\Class\Vendor\VendorFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{




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

        try {


    {

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
    }
}
