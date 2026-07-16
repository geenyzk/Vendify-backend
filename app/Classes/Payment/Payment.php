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
        // Used to Log::info() the full Provider models here — every call
        // wrote api_key/password/webhook_access to the log file in plain
        // text. Same credential-leak class already fixed once this session
        // in VendorFactory::sumAllBalances().
        $providers = Provider::getPaymentProviders()->get();
        Log::info('Generating payment accounts for user.', [
            'user_id' => $user->id,
            'providers' => $providers->pluck('name'),
        ]);
        $providers->map(function ($provider) use($user){
            PaymentFactory::make($provider)->generateAccount($user);
        });
    }

    /**
     * Generate a virtual account for an affiliate child's customer through the
     * first active payment provider that supports it (PaymentPoint today).
     * Returns the account details plus the provider name, or null if no
     * provider could create one.
     *
     * @param array{email?:string,name?:string,username?:string,phone?:string} $customer
     * @return array{provider:string,account_number:string,bank_name:?string,account_name:?string,reference:?string}|null
     */
    static function generateChildAccount(array $customer): ?array
    {
        foreach (Provider::getPaymentProviders()->get() as $provider) {
            try {
                $gateway = PaymentFactory::make($provider);
                $account = $gateway->generateForCustomer($customer);
            } catch (\Throwable $e) {
                Log::warning('Child virtual account generation failed for a provider', [
                    'provider' => $provider->name,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            if ($account && !empty($account['account_number'])) {
                return array_merge($account, ['provider' => strtolower(trim((string) $provider->name))]);
            }
        }

        return null;
    }

     static function webhook(Request $request, $identifier){
        $provider = Provider::whereIdentifier($identifier)->first();
        if (!$provider) {
            Log::warning('Payment webhook: unknown identifier', ['identifier' => $identifier]);
            return response()->noContent(404);
        }

        try {
            $providerInstance = PaymentFactory::make($provider);
            $verified = $providerInstance->webhook($request);
        } catch (\Throwable $e) {
            Log::error('Payment webhook processing failed', [
                'provider_id' => $provider->id,
                'error' => $e->getMessage(),
            ]);
            return response()->noContent();
        }

        // A signature that failed verification gets a 401 rather than the
        // usual 204 — distinguishes "rejected, try re-configuring the
        // webhook secret" from "accepted" in whoever's watching delivery
        // logs on the provider's own dashboard.
        return $verified === false ? response()->noContent(401) : response()->noContent();

    }
}
