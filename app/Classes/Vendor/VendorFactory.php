<?php

namespace App\Classes\Vendor;

use App\Classes\Vendor\Providers\Adex;
use App\Classes\Vendor\Providers\Ogdams;
use App\Classes\Vendor\Providers\SandboxService;
use App\Classes\Vendor\Providers\SimVending;
use App\Classes\Vendor\Providers\SMEPlug;
use App\Classes\Vendor\Providers\Vtpass;
use App\Classes\Vendor\Providers\VTUNg;
use App\Models\Vendor;
use Illuminate\Http\Request;
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
        'vtu_ng'   => VTUNg::class,
        'ogdams'   => Ogdams::class,
        'sandbox'  => SandboxService::class,
        // Deliberately absent from PROVIDER_META: SIM vending is the
        // platform's own fleet, managed under its dedicated admin surface
        // (/admin/sim-vending), never through the "add provider" form. Its
        // single Vendor row is created by SimVendingSeeder so the routing
        // pipeline can target it.
        'simvend'  => SimVending::class,
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
        'simhost'  => ['ogdams', ['api_key'], true],
        'vtpass'   => ['VTpass', ['api_key', 'public_key'], true],
        'vtu_ng'   => ['VTU.ng', ['username', 'password', 'api_key'], true],
    ];

    /**
     * Create a new class instance.
     */
    public function __construct(){}

    static function make (Vendor $provider) {
        $useSandbox = env('USE_SANDBOX', false);

        $match = $useSandbox ? "sandbox" : ($provider->sub_category === "simhost" ? self::normalizeProviderName((string) $provider->name) : $provider->sub_category);

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

    private static function normalizeProviderName(string $name): string
    {
        $normalized = strtolower(trim($name));

        return match (str_replace([' ', '_', '-'], '', $normalized)) {
            'ogdams' => 'ogdams',
            default => $normalized,
        };
    }


    public static function sumAllBalances(): float
    {
        $total = 0.0;
        // Active vendors only: an inactive row is by definition not vending,
        // and pinging one (often mid-configuration, with bad credentials)
        // just burns dashboard load time on a request bound to fail.
        Vendor::where('active', true)->get()->each(function ($vendor) use (&$total) {
            $balance = $vendor->balance;
            if ($balance !== null && is_numeric(str_replace(',', '', (string) $balance))) {
                $total += (float) str_replace(',', '', (string) $balance);
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
