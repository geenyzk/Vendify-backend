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
    /**
     * Every virtual bank account the parent can offer an affiliate child's
     * customer — one entry per bank across all active payment providers (e.g.
     * PalmPay + 9PSB from PaymentPoint), each tagged with the issuing provider.
     * The child stores each in its matching hard-coded bank slot.
     *
     * @return array<int, array{account_number:string,bank_name:?string,account_name:?string,reference:?string,provider:string}>
     */
    static function generateChildAccounts(array $customer): array
    {
        $accounts = [];

        foreach (Provider::getPaymentProviders()->get() as $provider) {
            try {
                $gateway = PaymentFactory::make($provider);
                $result = $gateway->generateForCustomer($customer);
            } catch (\Throwable $e) {
                Log::warning('Child virtual account generation failed for a provider', [
                    'provider' => $provider->name,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            if (empty($result)) {
                continue;
            }

            // A provider returns either a single account (legacy shape) or a
            // list of them (PaymentPoint, one per bank) — normalise to a list.
            $list = array_key_exists('account_number', $result) ? [$result] : $result;
            $providerName = strtolower(trim((string) $provider->name));

            foreach ($list as $account) {
                if (!is_array($account) || empty($account['account_number'])) {
                    continue;
                }
                $accounts[] = array_merge($account, ['provider' => $providerName]);
            }
        }

        return $accounts;
    }

    /**
     * Backward-compatible single-account helper — the first bank account from
     * generateChildAccounts(), or null when none could be created.
     *
     * @return array{account_number:string,bank_name:?string,account_name:?string,reference:?string,provider:string}|null
     */
    static function generateChildAccount(array $customer): ?array
    {
        return self::generateChildAccounts($customer)[0] ?? null;
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
