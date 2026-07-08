<?php

namespace App\Classes\Payment;

use App\Models\Provider;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class Payment
{
    /**
     * Create a new class instance.
     */
    public function __construct()
    {
        //
    }


    static function generateAccount(User $user){
        $providers = Provider::getPaymentProviders()->get();
        Log::info($providers);
        $providers->map(function ($provider) use($user){
            PaymentFactory::make($provider)->generateAccount($user);
        });
    }

     static function webhook(Request $request, $identifier){
        $vendor = Provider::whereIdentifier($identifier)->first();
        $vendorInstance = PaymentFactory::make($vendor);
        $vendorInstance->webhook($request);
        return response()->noContent();

    }
}
