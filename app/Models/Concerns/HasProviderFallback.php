<?php

namespace App\Models\Concerns;

use App\Models\Provider;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait HasProviderFallback
{
    private function providerRoutingRow(): ?object
    {
        try {
            $query = DB::table('providerables')
                ->where('providerable_id', $this->getKey())
                ->where('providerable_type', self::class);

            return Schema::hasColumn('providerables', 'priority')
                ? $query->orderBy('priority')->first()
                : $query->first();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @return array<int, array{provider_id:int, server_id:?string, cost_price:?float, provider_discount:?float}>
     */
    private function providerFallbackRows(): array
    {
        $row = $this->providerRoutingRow();
        if (! $row) {
            return [];
        }

        $fallbacks = property_exists($row, 'fallbacks') ? $row->fallbacks : null;
        $decoded = is_string($fallbacks) && $fallbacks !== ''
            ? json_decode($fallbacks, true)
            : (is_array($fallbacks) ? $fallbacks : null);

        if (is_array($decoded)) {
            $rows = [];
            foreach ($decoded as $entry) {
                if (! is_array($entry) || empty($entry['provider_id'])) {
                    continue;
                }

                $rows[] = [
                    'provider_id' => (int) $entry['provider_id'],
                    'server_id' => ($entry['server_id'] ?? null) !== null && $entry['server_id'] !== ''
                        ? (string) $entry['server_id']
                        : null,
                    'cost_price' => ($entry['cost_price'] ?? null) !== null && $entry['cost_price'] !== ''
                        ? (float) $entry['cost_price']
                        : null,
                    'provider_discount' => ($entry['provider_discount'] ?? null) !== null && $entry['provider_discount'] !== ''
                        ? (float) $entry['provider_discount']
                        : null,
                ];
            }

            return $rows;
        }

        if (($row->fallback_provider_id ?? null) === null) {
            return [];
        }

        return [[
            'provider_id' => (int) $row->fallback_provider_id,
            'server_id' => ($row->fallback_server_id ?? null) !== null ? (string) $row->fallback_server_id : null,
            'cost_price' => ($row->fallback_cost_price ?? null) !== null ? (float) $row->fallback_cost_price : null,
            'provider_discount' => ($row->fallback_provider_discount ?? null) !== null ? (float) $row->fallback_provider_discount : null,
        ]];
    }

    private function fallbackRowFor(?int $providerId): ?array
    {
        if ($providerId === null) {
            return null;
        }

        foreach ($this->providerFallbackRows() as $row) {
            if ((int) $row['provider_id'] === $providerId) {
                return $row;
            }
        }

        return null;
    }

    public function getFallbacksAttribute(): array
    {
        $rows = $this->providerFallbackRows();
        if ($rows === []) {
            return [];
        }

        $providers = Provider::whereIn('id', array_column($rows, 'provider_id'))
            ->get()
            ->keyBy('id');

        return array_map(function (array $row) use ($providers) {
            $provider = $providers->get($row['provider_id']);

            return [
                'provider_id' => $row['provider_id'],
                'server_id' => $row['server_id'],
                'cost_price' => $row['cost_price'],
                'provider_discount' => $row['provider_discount'],
                'provider' => $provider ? [
                    'id' => $provider->id,
                    'name' => $provider->name,
                ] : null,
            ];
        }, $rows);
    }

    public function getFallbackProviderIdAttribute(): ?int
    {
        $id = $this->providerFallbackRows()[0]['provider_id'] ?? null;

        return $id !== null ? (int) $id : null;
    }

    public function getFallbackServerIdAttribute(): ?string
    {
        $value = $this->providerFallbackRows()[0]['server_id'] ?? null;

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
        $value = $this->providerFallbackRows()[0]['cost_price'] ?? null;

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
        $fallback = $this->fallbackRowFor($providerId);
        $isFallback = $fallback !== null
            && (int) ($row->provider_id ?? 0) !== $providerId;

        if ($isFallback && ($fallback['cost_price'] ?? null) !== null) {
            return (float) $fallback['cost_price'];
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
        $value = $this->providerFallbackRows()[0]['provider_discount'] ?? null;

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

        $fallback = $this->fallbackRowFor($providerId);
        $isFallback = $fallback !== null
            && (int) ($row->provider_id ?? 0) !== $providerId;

        $value = $isFallback
            ? ($fallback['provider_discount'] ?? null)
            : ($row->provider_discount ?? null);

        return $value !== null ? (float) $value : null;
    }

    public function resolveFallbackVendor(array $excludeProviderIds = []): ?Vendor
    {
        $excluded = array_flip(array_map('intval', $excludeProviderIds));
        foreach ($this->providerFallbackRows() as $fallback) {
            $id = (int) $fallback['provider_id'];
            if (isset($excluded[$id])) {
                continue;
            }

            $vendor = Vendor::find($id);
            if ($vendor) {
                return $vendor;
            }
        }

        return null;
    }
}
