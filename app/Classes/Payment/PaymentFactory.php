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
