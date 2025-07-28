<?php

namespace App\Class\Payment;

use App\Class\Payment\Provider\FlutterWave;
use App\Class\Payment\Provider\Monnify;
use App\CLass\Payment\Provider\PaymentPoint;
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
        return match($provider->name){
            "flutterwave" => new FlutterWave($provider),
            "monnify" => new Monnify($provider),
            "payment point" => new PaymentPoint($provider),
        };
    }
}
