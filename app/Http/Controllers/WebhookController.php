<?php

namespace App\Http\Controllers;

use App\Class\ChildSync\ChildAuthenticator;
use App\Class\ChildSync\ChildSyncFactory;
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
