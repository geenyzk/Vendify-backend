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

    public function resolveFallbackVendor(): ?Vendor
    {
        $id = $this->fallback_provider_id;

        return $id ? Vendor::find($id) : null;
    }
}
