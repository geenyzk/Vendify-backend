<?php

namespace App\Classes\Vendor;

use App\Classes\Vendor\Providers\Adex;
use App\Classes\Vendor\Providers\Ogdams;
use App\Classes\Vendor\Providers\SandboxService;
use App\Classes\Vendor\Providers\SMEPlug;
use App\Classes\Vendor\Providers\Vtpass;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class VendorFactory
{
    /**
     * Create a new class instance.
     */
    public function __construct(){}

    static function make (Vendor $provider) {
        $useSandbox = env('USE_SANDBOX', false);

        $match = $useSandbox ? "sandbox":($provider->sub_category === "simhost" ? $provider->name : $provider->sub_category);

        // No `default` arm used to mean an unmapped sub_category (a typo, a
        // new provider added via the admin table with no matching class
        // yet, or "vtpass" — a fully-built provider class that was simply
        // never wired in here) threw an UnhandledMatchError straight out of
        // a real purchase request instead of a clear, catchable error.
        return match ($match) {
            "adex"=> new Adex($provider),
            "sandbox"=> new SandboxService() ,
            "sme plug"=> new SMEPlug($provider),
            "spurs"=> new Adex($provider),
            "msorg"=> new Adex($provider),
            "vtpass"=> new Vtpass($provider),
            // Matched either via sub_category="ogdams" directly, or the
            // sub_category="simhost" + name="ogdams" convention above (the
            // same one the pre-existing "simhost" special-case was clearly
            // set up for, just never had a matching arm).
            "ogdams"=> new Ogdams($provider),
            default => throw new \InvalidArgumentException(
                "No vendor class mapped for sub_category/name [{$match}] (provider #{$provider->id}, {$provider->name})."
            ),
        };
    }


    public static function sumAllBalances(): float
    {
        $total = 0.0;
        Vendor::all()->each(function ($vendor) use (&$total) {
            try {
                $vendorInstance = self::make($vendor);
                $total += (float) str_replace(',', '', $vendorInstance->checkBalance());
            } catch (\Throwable $e) {
                // Never log the full Vendor model here — it carries
                // username/password/api_key/secret_key, and this used to go
                // straight into the log on every single failure.
                Log::warning("Failed to fetch balance for vendor [{$vendor->name}]: " . $e->getMessage(), [
                    'vendor_id' => $vendor->id,
                    'sub_category' => $vendor->sub_category,
                ]);
            }
        });

        return $total;
    }

    static function webhook(Request $request, $identifier){
        $vendor = Vendor::whereIdentifier($identifier)->first();
        if (!$vendor) {
            Log::warning('Vendor webhook: unknown identifier', ['identifier' => $identifier]);
            return response()->noContent(404);
        }

        try {
            $vendorInstance = self::make($vendor);
            $vendorInstance->webhook($request);
        } catch (\Throwable $e) {
            Log::error('Vendor webhook processing failed', [
                'vendor_id' => $vendor->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->noContent();
    }

}
