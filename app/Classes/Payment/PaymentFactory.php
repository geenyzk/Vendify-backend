<?php

namespace App\Classes\Payment;

use App\Classes\Payment\Provider\FlutterWave;
use App\Classes\Payment\Provider\Monnify;
use App\Classes\Payment\Provider\PaymentPoint;


use App\Models\Provider;

class PaymentFactory
{
    /**
     * Single source of truth: which class handles each gateway, keyed by the
     * (lowercased) provider name. make() and availableGateways() both read this
     * so the admin form's options never drift from what the code supports.
     */
    private const REGISTRY = [
        'flutterwave'  => FlutterWave::class,
        'monnify'      => Monnify::class,
        'payment point' => PaymentPoint::class,
        'paymentpoint' => PaymentPoint::class,
    ];

    // Selectable gateways and the exact provider columns each authenticates
    // with — [key, label, secret]. NB the `providers` table has no secret_key
    // column, so Monnify's secret lives in `password` (its own convention).
    private const GATEWAY_META = [
        'Flutterwave' => [
            ['public_key', 'Public key', false],
            ['secret_key', 'Secret key', true],
            ['encryption_key', 'Encryption key', true],
            ['webhook_access', 'Webhook secret hash', true],
        ],
        'Monnify' => [
            ['api_key', 'API key', false],
            ['password', 'Secret key', true],
        ],
        'PaymentPoint' => [
            ['api_key', 'API key (public)', false],
            ['password', 'API token', true],
            ['username', 'Business ID', false],
            ['webhook_access', 'Webhook HMAC key', true],
        ],
    ];

    /**
     * Create a new class instance.
     */
    public function __construct()
    {
        //
    }
    static function make(Provider $provider){
        $providerName = strtolower(trim((string) $provider->name));

        $class = self::REGISTRY[$providerName] ?? null;
        if (!$class) {
            throw new \InvalidArgumentException("Unsupported payment provider: {$provider->name}");
        }

        return new $class($provider);
    }

    /**
     * The payment gateways an admin can configure, each with its label and the
     * credential fields it needs — so the "add gateway" form can offer a real
     * dropdown of engines and render the right inputs for the one chosen.
     */
    public static function availableGateways(): array
    {
        $out = [];
        foreach (self::GATEWAY_META as $name => $creds) {
            $out[] = [
                'value' => $name,
                'label' => $name,
                'credentials' => array_map(fn ($c) => [
                    'key' => $c[0],
                    'label' => $c[1],
                    'secret' => $c[2],
                ], $creds),
            ];
        }
        return $out;
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
