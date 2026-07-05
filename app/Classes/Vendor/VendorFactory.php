<?php

namespace App\Classes\Vendor;

use App\Classes\Vendor\Providers\Adex;
use App\Classes\Vendor\Providers\SandboxService;
use App\Classes\Vendor\Providers\SMEPlug;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

use function Laravel\Prompts\error;

class VendorFactory
{
    /**
     * Create a new class instance.
     */
    public function __construct(){}

    static function make (Vendor $provider) {
        $useSandbox = env('USE_SANDBOX', false);

        $match = $useSandbox ? "sandbox":($provider->sub_category === "simhost" ? $provider->name : $provider->sub_category);
        // Log::info($useSandbox);
        return match ($match) {
            "adex"=> new Adex($provider),
            "sandbox"=> new SandboxService() ,
            "sme plug"=> new SMEPlug($provider),
            "spurs"=> new Adex($provider),
            "msorg"=> new Adex($provider),
        } ;
    }


    public static function sumAllBalances(): float
    {

         $total = 0.0;
            Vendor::all()->map(function ($vendor) use (&$total) {
            try {
                Log::info($vendor);
                $vendorInstance = self::make($vendor);
                $total += (float) str_replace(',', '', $vendorInstance->checkBalance());
            } catch (\Throwable $e) {
                error_log($e);
                Log::warning("Failed to fetch balance for vendor [{$vendor->name}]: " . $e->getMessage());
            }
        });

        error_log($total);
        return $total;

    }

    static function webhook(Request $request, $identifier){
        $vendor = Vendor::whereIdentifier($identifier)->first();
        $vendorInstance = self::make($vendor);
        $vendorInstance->webhook($request);
        return response()->noContent();

    }

}
