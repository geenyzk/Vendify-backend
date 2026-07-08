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
