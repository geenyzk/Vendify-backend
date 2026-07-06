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

    /**
     * The active payment gateway (enabled via Service Control's payment
     * gateway toggle) that can actually disburse to a bank account — used
     * for wallet-to-bank withdrawals. Null if none is configured/active, in
     * which case withdrawals must wait for manual/admin handling.
     */
    static function makeTransferCapable(): ?PaymentBase
    {
        $providers = Provider::where('category', 'payment')->getPaymentProviders()->get();

        foreach ($providers as $provider) {
            try {
                $gateway = self::make($provider);
                if ($gateway->supportsTransfers()) {
                    return $gateway;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        return null;
    }
}
