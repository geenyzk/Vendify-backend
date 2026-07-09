<?php

namespace App\Http\Controllers;

use App\Classes\ChildSync\ChildAuthenticator;
use App\Classes\ChildSync\ChildSyncFactory;
use App\Classes\Payment\Payment;
use App\Classes\Vendor\VendorFactory;
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

        try {
            switch ($type) {
                case 'payment':
                    return Payment::webhook($request, $identifier) ;

                // The push half of the parent<->child channel: the child
                // uploads customer/transaction snapshots here. This branch
                // was silently dropped by the 2026-07-08 merge, which left
                // ChildSyncFactory with no caller — affiliates never synced.
                case 'child':
                    return $this->childWebhook($request, $identifier);

                case 'vendor':
                default:
                    return VendorFactory::webhook($request, $identifier) ;
            }
        } catch (\Throwable $th) {
            //throw $th;
            $this->fail([], "Unauthorized", 401);
        }
    }

    // Same {type}/{identifier} shape as the vendor/payment cases above, but
    // unlike those (which have no payload verification at all), a growing
    // set of less-trusted child instances needs the request signature
    // checked before ChildSyncFactory touches anything.
    protected function childWebhook(Request $request, string $identifier)
    {
        [$instance, $error] = ChildAuthenticator::verify($request, $identifier);
        if (!$instance) {
            return $this->fail([], $error, 401);
        }

        return ChildSyncFactory::webhook($request, $instance);
    }
}
