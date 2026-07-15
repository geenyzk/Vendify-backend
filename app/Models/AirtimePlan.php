<?php

namespace App\Models;

use App\Models\Concerns\HasProviderFallback;
use Illuminate\Database\Eloquent\Model;

class AirtimePlan extends Model
{
    use HasProviderFallback;

    protected $fillable = ["name", "category", "type", "min", "max", "active"];

    protected $casts = [
        "active" => "boolean",
    ];

    // Expose the attached provider (and whether one is set) so the admin form
    // can pre-fill the provider picker on edit — mirrors DataPlan.
    protected $appends = ["provider", "use_provider_as_providerable", "fallback_provider", "fallback_provider_id", "fallback_server_id"];

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

    /**
     * The single provider attached to this airtime plan, if any — falls back
     * to the network's default provider (via its active network type), same
     * as DataPlan.
     */
    public function getProviderAttribute()
    {
        $provider = $this->providers()->first();
        if ($provider) {
            return $provider;
        }

        try {
            $network = Network::where('name', $this->name)->first();
            if ($network) {
                $networkType = $network->networkTypes()->wherePivot('active', 1)->first();
                if ($networkType && method_exists($networkType, 'provider')) {
                    return $networkType->provider;
                }
            }
        } catch (\Throwable $e) {
            // swallow and return null on error
        }

        return null;
    }

    public function getUseProviderAsProviderableAttribute()
    {
        if ($this->relationLoaded('providers')) {
            return !empty($this->getRelation('providers'));
        }

        return $this->providers()->exists();
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
        $provider = $this->providers()->first();
        if (!$provider) {
            return null;
        }

        return Vendor::find($provider->id);
    }
}
