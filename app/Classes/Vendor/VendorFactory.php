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
     * Single source of truth: which vendor class fulfils each provider "type"
     * key (matched against sub_category, or name when sub_category="simhost").
     * make() and availableProviders() both read this so they never drift.
     */
    private const REGISTRY = [
        'adex'     => Adex::class,
        'spurs'    => Adex::class,
        'msorg'    => Adex::class,
        'sme plug' => SMEPlug::class,
        'vtpass'   => Vtpass::class,
        'ogdams'   => Ogdams::class,
        'sandbox'  => SandboxService::class,
    ];

    // Provider credential columns each integration authenticates with, plus the
    // label/secret hint the admin form should render for each field.
    private const CREDENTIAL_FIELDS = [
        'username'   => ['label' => 'API username', 'secret' => false],
        'password'   => ['label' => 'API password / secret', 'secret' => true],
        'api_key'    => ['label' => 'API key', 'secret' => true],
        'public_key' => ['label' => 'Public key', 'secret' => false],
    ];

    // Admin-selectable provider types: [label, credential columns, has base_url].
    // "sandbox" is a global test switch, not a per-provider type, so it's here
    // in REGISTRY (for make) but deliberately not offered to admins.
    private const PROVIDER_META = [
        'adex'     => ['Adex', ['username', 'password'], true],
        'spurs'    => ['Spurs', ['username', 'password'], true],
        'msorg'    => ['Msorg', ['username', 'password'], true],
        'sme plug' => ['SME Plug', ['api_key'], true],
        'ogdams'   => ['Ogdams', ['api_key'], true],
        'vtpass'   => ['VTpass', ['api_key', 'public_key'], true],
    ];

    /**
     * Create a new class instance.
     */
    public function __construct(){}

    static function make (Vendor $provider) {
        $useSandbox = env('USE_SANDBOX', false);

        $match = $useSandbox ? "sandbox" : ($provider->sub_category === "simhost" ? $provider->name : $provider->sub_category);

        // An unmapped sub_category (a typo, a new provider added via the admin
        // table with no matching class yet) resolves to null here — throw a
        // clear, catchable error instead of an UnhandledMatchError straight out
        // of a real purchase request.
        $class = self::REGISTRY[$match] ?? null;
        if (!$class) {
            throw new \InvalidArgumentException(
                "No vendor class mapped for sub_category/name [{$match}] (provider #{$provider->id}, {$provider->name})."
            );
        }

        return new $class($provider);
    }

    /**
     * The provider integrations an admin can configure, each with its label,
     * whether it takes a base URL, and the exact credential fields it needs —
     * so the "add provider" form can list every supported type and render the
     * right credential inputs for the one chosen.
     */
    public static function availableProviders(): array
    {
        $out = [];
        foreach (self::PROVIDER_META as $value => [$label, $creds, $hasBaseUrl]) {
            $out[] = [
                'value' => $value,
                'label' => $label,
                'base_url' => $hasBaseUrl,
                'credentials' => array_map(fn ($key) => [
                    'key' => $key,
                    'label' => self::CREDENTIAL_FIELDS[$key]['label'],
                    'secret' => self::CREDENTIAL_FIELDS[$key]['secret'],
                ], $creds),
            ];
        }
        return $out;
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
