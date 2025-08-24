<?php

namespace App\Class\Vendor;

use App\Class\Vendor\Providers\Adex;
use App\Class\Vendor\Providers\SandboxService;
use App\Class\Vendor\Providers\SMEPlug;
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
        Log::info($useSandbox);
        return match ($match) {
            "adex"=> new Adex($provider),
            "sandbox"=> new SandboxService() ,
            "sme plug"=> new SMEPlug($provider),
            "spurs"=> new Adex($provider),
        } ;
    }


    public static function sumAllBalances(): float
    {

         $total = 0.0;
            Vendor::all()->map(function ($vendor) use (&$total) {
            try {
                // Log::info($vendor);
                $vendorInstance = self::make($vendor);
                $total += $vendorInstance->checkBalance();
            } catch (\Throwable $e) {
                Log::warning("Failed to fetch balance for vendor [{$vendor->name}]: " . $e->getMessage());
            }
        });

        return $total;

    }

    static function webhook(Request $request, $identifier){
        $vendor = Vendor::whereIdentifier($identifier)->first();
        $vendorInstance = self::make($vendor);
        $vendorInstance->webhook($request);
        return response()->noContent();

    }

}
