<?php

namespace App\Models\Concerns;

use App\Models\Provider;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;

trait HasProviderFallback
{
    private function providerRoutingRow(): ?object
    {
        try {
            return DB::table('providerables')
                ->where('providerable_id', $this->getKey())
                ->where('providerable_type', self::class)
                ->first();
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function getFallbackProviderIdAttribute(): ?int
    {
        $id = $this->providerRoutingRow()?->fallback_provider_id ?? null;

        return $id !== null ? (int) $id : null;
    }

    public function getFallbackServerIdAttribute(): ?string
    {
        $value = $this->providerRoutingRow()?->fallback_server_id ?? null;

        return $value !== null ? (string) $value : null;
    }

    public function getFallbackProviderAttribute(): ?Provider
    {
        $id = $this->fallback_provider_id;

        return $id ? Provider::find($id) : null;
    }

    /**
     * What the fallback provider charges us for this plan. Null means "no
     * distinct fallback price" — callers should read the primary cost_price.
     */
    public function getFallbackCostPriceAttribute(): ?float
    {
        $value = $this->providerRoutingRow()?->fallback_cost_price ?? null;

        return $value !== null ? (float) $value : null;
    }

    /**
     * The cost of this plan from a specific provider. Use when you know which
     * vendor served (or will serve) a sale — a fallback resells at its own
     * price, so costing a failed-over sale at the primary's price overstates
     * or understates profit.
     *
     * Reads the routing row directly rather than delegating to the model's own
     * resolveCostPrice(): AirtimePlan uses this trait but has no such method,
     * and both read the same providerables row anyway.
     */
    public function costPriceFor(?int $providerId): float
    {
        $row = $this->providerRoutingRow();
        if (!$row) {
            return 0.0;
        }

        // Only a *different* vendor in the fallback slot counts as a fallback
        // sale; a plan may legitimately name the same vendor in both slots.
        $isFallback = $providerId !== null
            && (int) ($row->fallback_provider_id ?? 0) === $providerId
            && (int) ($row->provider_id ?? 0) !== $providerId;

        if ($isFallback && ($row->fallback_cost_price ?? null) !== null) {
            return (float) $row->fallback_cost_price;
        }

        return (float) ($row->cost_price ?? 0);
    }

    /**
     * The commission the primary provider gives us off face value, as a
     * percentage (3.50 = they bill us 96.5% of face). Null = not configured.
     */
    public function getProviderDiscountAttribute(): ?float
    {
        $value = $this->providerRoutingRow()?->provider_discount ?? null;

        return $value !== null ? (float) $value : null;
    }

    /** As provider_discount, but for the fallback provider's own agreement. */
    public function getFallbackProviderDiscountAttribute(): ?float
    {
        $value = $this->providerRoutingRow()?->fallback_provider_discount ?? null;

        return $value !== null ? (float) $value : null;
    }

    /**
     * The face-value discount offered by a specific provider, or null when
     * that provider has none configured. Each vendor negotiates its own rate,
     * so a failed-over sale must use the fallback's figure.
     */
    public function discountFor(?int $providerId): ?float
    {
        $row = $this->providerRoutingRow();
        if (!$row) {
            return null;
        }

        $isFallback = $providerId !== null
            && (int) ($row->fallback_provider_id ?? 0) === $providerId
            && (int) ($row->provider_id ?? 0) !== $providerId;

        $value = $isFallback
            ? ($row->fallback_provider_discount ?? null)
            : ($row->provider_discount ?? null);

        return $value !== null ? (float) $value : null;
    }

    public function resolveFallbackVendor(): ?Vendor
    {
        $id = $this->fallback_provider_id;

        return $id ? Vendor::find($id) : null;
    }
}
