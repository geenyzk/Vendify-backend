<?php

namespace App\Classes\Payment;

use App\Classes\Payment\Provider\FlutterWave;
use App\Classes\Payment\Provider\Monnify;
use App\Classes\Payment\Provider\PaymentPoint;


use App\Models\Provider;

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
        $providerName = strtolower(trim((string) $provider->name));

        return match($providerName){
            "flutterwave" => new FlutterWave($provider),
            "monnify" => new Monnify($provider),
            "payment point", "paymentpoint" => new PaymentPoint($provider),
            default => throw new \InvalidArgumentException("Unsupported payment provider: {$provider->name}"),
        };
    }
}
