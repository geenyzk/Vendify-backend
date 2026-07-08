<?php

namespace App\Classes\Payment;

use App\Classes\Payment\Provider\FlutterWave;
use App\Classes\Payment\Provider\Monnify;

use App\Classes\Payment\Provider\PaymentPoint;


use App\Models\Provider;
use Illuminate\Support\Facades\Log;

class PaymentFactory
{
    /**
     * Create a new class instance.
     */
    public function __construct()
    {
        //
    }
    static function make(Provider $provider){
        // No default arm used to mean any provider name that didn't match
        // exactly (a typo, or a real gap — e.g. "Payvessel" is a configured
        // payment provider in the DB with no class here at all) threw an
        // uncaught UnhandledMatchError straight out of a webhook/generate
        // request instead of a clear, catchable, loggable error.
        return match($provider->name){
            "flutterwave" => new FlutterWave($provider),
            "monnify" => new Monnify($provider),
            "payment point" => new PaymentPoint($provider),
            default => throw new \InvalidArgumentException(
                "No payment provider class mapped for [{$provider->name}] (provider #{$provider->id})."
            ),
        };
    }
}
