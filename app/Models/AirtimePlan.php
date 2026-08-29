<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasProviderFallback;
use Illuminate\Database\Eloquent\Model;

class AirtimePlan extends Model
{
    use Auditable;
    use HasProviderFallback;

    protected $fillable = ["name", "category", "type", "min", "max", "active"];

    protected $casts = [
        "active" => "boolean",
    ];

    // Expose the attached provider (and whether one is set) so the admin form
    // can pre-fill the provider picker on edit — mirrors DataPlan.
    protected $appends = ["provider", "primary_provider", "routing_status", "fallbacks", "fallback_provider", "fallback_provider_id", "fallback_server_id", "fallback_cost_price", "provider_discount", "fallback_provider_discount"];

    public function toArray()
    {
        $array = parent::toArray();
        $array['network'] = $array['name'];

        return $array;
    }

    /**
     * Polymorphic provider association (same `providerables` pivot as
     * DataPlan) — which vendor/server this network's airtime maps to, plus
     * an optional cost price. Written by AdminController::syncModelRelations
     * from the form's `providerable` payload.
     */
    public function providers()
    {
        return $this->morphToMany(Provider::class, 'providerable', 'providerables', 'providerable_id', 'provider_id')
            ->withPivot(['cost_price', 'margin_value', 'margin_type', 'server_id'])
            ->withTimestamps();
    }

    /** The explicitly assigned primary provider. No implicit routing source. */
    public function getProviderAttribute()
    {
        return $this->providers()->whereNotNull('providers.id')->first();
    }

    public function getPrimaryProviderAttribute()
    {
        return $this->provider;
    }

    public function getRoutingStatusAttribute(): string
    {
        return $this->provider ? 'active' : 'not_configured';
    }

    /**
     * The Vendor that should fulfil an airtime purchase of this plan: the
     * provider explicitly attached via the create/edit form's custom-provider
     * toggle (the `providerables` pivot). Returns null when no plan-specific
     * provider is set, so callers fall back to the Stock Vending assignment.
     *
     * `providers()` yields a Provider (a raw `providers` row with no scope);
     * re-resolve it as a Vendor so it carries the category=vendor global scope
     * that VendorFactory::make() and the vendor classes expect.
     */
    public function resolveVendor(): ?Vendor
    {
        $provider = $this->provider;
        if (!$provider) {
            return null;
        }

        return Vendor::find($provider->id);
    }
}
