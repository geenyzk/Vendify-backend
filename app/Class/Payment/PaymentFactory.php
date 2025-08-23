<?php

namespace App\Class\Payment;

use App\Class\Payment\Provider\FlutterWave;
use App\Class\Payment\Provider\Monnify;
use App\Class\Payment\Provider\PaymentPoint;
use App\Models\Provider;
use Illuminate\Support\Facades\Log;

class PaymentFactory
{
    /**
     * http://192.168.1.166:5173/customer
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
