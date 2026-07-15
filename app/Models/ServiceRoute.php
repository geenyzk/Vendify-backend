<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

class ServiceRoute extends Model
{
    use Auditable;
    protected $fillable = ['service_type', 'route_key', 'provider_id'];

    protected $casts = [
        'provider_id' => 'integer',
    ];

    public function provider()
    {
        return $this->belongsTo(Vendor::class, 'provider_id');
    }

    /**
     * The vendor an admin has routed this (service_type, route_key) to, or null
     * if none is set. Both sides are matched case- and separator-insensitively
     * so "SME"/"sme" and "cooperate_gifting"/"cooperate gifting" resolve the
     * same way the free-text keys arrive from sync / requests.
     *
     * `provider()` (a Vendor) carries the category=vendor global scope that
     * VendorFactory::make() and the vendor classes expect; re-resolving via
     * Vendor::find keeps that guarantee even though we matched on a raw row.
     */
    public static function resolveVendor(string $serviceType, ?string $routeKey): ?Vendor
    {
        $normalize = fn ($value): string => strtolower(str_replace(['_', '-'], ' ', trim((string) $value)));
        $wantedType = $normalize($serviceType);
        $wantedKey = $normalize($routeKey);

        // The routing table is tiny (a handful of rows per install), so match in
        // PHP rather than pushing DB-specific REPLACE() gymnastics into SQL.
        // Guarded so a code deploy that lands before `php artisan migrate` (the
        // FTP deploy flow can) never breaks a live purchase — it just falls
        // through to the legacy Stock Vending routing.
        try {
            $routes = self::whereNotNull('provider_id')->get();
        } catch (\Throwable $e) {
            return null;
        }

        $route = $routes->first(fn ($r) => $normalize($r->service_type) === $wantedType
            && $normalize($r->route_key) === $wantedKey);

        if (!$route) {
            return null;
        }

        return Vendor::find($route->provider_id);
    }
}
