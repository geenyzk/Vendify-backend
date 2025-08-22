<?php

namespace App\Class\Payment;

use App\Models\Provider;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class Payment
{

    static function generateAccount(User $user){
        $providers = Provider::getPaymentProviders()->get();
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
