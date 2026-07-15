<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\HasServers;
use App\Models\Concerns\HasProviderFallback;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class CablePlan extends Model
{
    use Auditable;
    //
    use HasServers, HasProviderFallback;

    protected $appends = [
        "status", "price", "servers", "price_ngn", "charge_fee_amount",
        "provider", "use_provider_as_providerable", "cost_price", "server_id",
        "fallback_provider", "fallback_provider_id", "fallback_server_id", "fallback_cost_price",
    ];
    protected $casts = [
        "active" => "boolean",
        "charge_fee" => "array",
    ];

    protected function getPriceNgnAttribute()
    {
        return '₦' . number_format($this->price, 2);
    }

     protected static function booted()
    {
        static::retrieved(function ($model) {
            if (env('APP_TYPE', "standalone") === 'affiliate') {
                foreach (range(2, 5) as $i) {
                    unset(
                        $model->{"adex_server_$i"},
                        $model->{"spurs_server_$i"},
                        $model->{"msorg_server_$i"}
                    );
                }

                unset($model->spurs_server_1, $model->msorg_server_1, $model->vtpass, $model->payscribe);
            }
        });
    }



     protected $fillable = [
        'cable_network', 'plan_name', 'active', 'charge_fee',
        'adex_server_1', 'adex_server_2', 'adex_server_3', 'adex_server_4', 'adex_server_5',
        'spurs_server_1', 'spurs_server_2', 'spurs_server_3', 'spurs_server_4', 'spurs_server_5',
        'msorg_server_1', 'msorg_server_2', 'msorg_server_3', 'msorg_server_4', 'msorg_server_5',
        'vtpass', 'payscribe',
    ];

      public function getStatusAttribute(){
        return $this->active ? "active": 'inactive';
    }

    /**
     * Providers (vendors) that supply this cable plan, with pivot fields
     * Pivot contains: cost_price, margin_value, margin_type, server_id.
     * Same polymorphic relation DataPlan uses — see AdminController::
     * syncModelRelations, which is generic over any model exposing this.
     */
    public function providers()
    {
        return $this->morphToMany(Provider::class, 'providerable', 'providerables', 'providerable_id', 'provider_id')
            ->withPivot(['cost_price', 'margin_value', 'margin_type', 'server_id'])
            ->withTimestamps();
    }

    public function getProviderAttribute()
    {
        return $this->providers()->first();
    }

    public function getUseProviderAsProviderableAttribute()
    {
        if ($this->relationLoaded('providers')) {
            return !empty($this->getRelation('providers'));
        }

        return $this->providers()->exists();
    }

    public function resolveVendor(): ?Vendor
    {
        $provider = $this->providers()->first();

        return $provider ? Vendor::find($provider->id) : null;
    }

    /**
     * Cost price / vendor plan id from the providerables pivot, exposed at the
     * top level so the edit form reloads them even when the plan has NO
     * plan-specific provider attached (provider_id null) — the pivot row still
     * carries them since cost price was decoupled from the provider toggle.
     * Mirrors DataPlanResource's own cost_price/server_id fallback.
     */
    public function getCostPriceAttribute(): float
    {
        return $this->resolveCostPrice();
    }

    public function getServerIdAttribute()
    {
        $pivotServer = $this->providers()->first()?->pivot?->server_id;
        if ($pivotServer !== null) {
            return $pivotServer;
        }

        $row = \Illuminate\Support\Facades\DB::table('providerables')
            ->where('providerable_id', $this->id)
            ->where('providerable_type', self::class)
            ->first();

        return $row->server_id ?? null;
    }

    /**
     * The subscription cost the cable company charges — always resolved
     * from the attached provider's pivot, never a plan-level column (the
     * platform doesn't set this itself, unlike a data plan's markup price).
     */
    protected function resolveCostPrice(): float
    {
        $pivotCost = $this->providers()->first()?->pivot?->cost_price;
        if ($pivotCost !== null) {
            return (float) $pivotCost;
        }

        $row = \Illuminate\Support\Facades\DB::table('providerables')
            ->where('providerable_id', $this->id)
            ->where('providerable_type', self::class)
            ->first();

        return (float) ($row->cost_price ?? 0);
    }

    /**
     * The role-specific service charge added on top of the provider's
     * subscription cost — never a full replacement price, since the cable
     * company (not the platform) sets what the subscription itself costs.
     */
    public function getChargeFeeAmountAttribute(): float
    {
        $user = Auth::user();
        $role = $user?->role->name ?? "user";

        if (!is_array($this->charge_fee) || !array_key_exists($role, $this->charge_fee)) {
            return 0.0;
        }

        $entry = $this->charge_fee[$role];
        $value = (float) ($entry['value'] ?? 0);

        return ($entry['type'] ?? 'fiat') === 'percentage'
            ? round($this->resolveCostPrice() * ($value / 100), 2)
            : $value;
    }

    public function getPriceAttribute(): float
    {
        return round($this->resolveCostPrice() + $this->charge_fee_amount, 2);
    }

}
