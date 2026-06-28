<?php

namespace App\Models;

use App\HasServers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use App\Models\Network;

class DataPlan extends Model
{

    protected $appends = ["price_ngn", 'plan', "status", "price", 'provider', 'use_provider_as_providerable'];
    protected $fillable = [
        'id',
        'plan_name',
        'plan_size',
        'server_id',
        'network',
        'active',
        'validity',
        'sort_order',
        'pricing'
       ];
    protected $casts = [
        "active" => "boolean",
        'pricing' => 'array',
    ];


    // Note: Avoid overriding `save()` to prevent infinite recursion or unexpected
    // behavior. Relationship syncs (providerable) are handled in the
    // `AdminController::syncModelRelations` logic when creating/updating via API.
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


    public function toArray()
    {
        $array = parent::toArray();

        if (env('APP_TYPE', "standalone") === 'affiliate') {
            // Keep only adex_server_1, remove others
            foreach (range(2, 5) as $i) {
                unset(
                    $array["adex_server_$i"],
                    $array["spurs_server_$i"],
                    $array["msorg_server_$i"]
                );
            }

            unset($array["spurs_server_1"], $array["msorg_server_1"], $array["vtupass"], $array["payscribe"]);
        }

        return $array;
    }

    public function getPlanAttribute(){
        return $this->plan_name . $this->plan_size;
    }

     public function getStatusAttribute(){
        return $this->active ? "active": 'inactive';
    }


    public function getPriceAttribute(){
        $user = Auth::user();
        $type = $user?->role->name ?? "user";   // nullsafe operator
        $column = "{$type}_price"; // legacy column name

        // Prefer pricing JSON if present
        if (is_array($this->pricing) && array_key_exists($type, $this->pricing)) {
            return $this->pricing[$type];
        }

        // Fallback to legacy individual column if pricing JSON not available
        return $this->{$column} ?? null;
    }
    public function getNetworkAttribute($value)
    {
        return strtolower($value);
    }

    /**
     * Providers (vendors) that supply this data plan with pivot fields
     * Pivot contains: cost_price, margin_value, margin_type
     *
     * Use `providers()` for the relation (allows pivot fields),
     * and `provider` attribute accessor to get the single provider.
     */
    public function providers()
    {
        // Polymorphic many-to-many relation: this model can have multiple providers
        // Pivot table `providerables` holds `cost_price`, `margin_value`, `margin_type` and timestamps
        return $this->morphToMany(Provider::class, 'providerable', 'providerables', 'providerable_id', 'provider_id')
            ->withPivot(['cost_price', 'margin_value', 'margin_type', 'server_id'])
            ->withTimestamps();
    }

    /**
     * Convenience accessor: return the single provider for this data plan.
     * Access via `$dataPlan->provider` (will execute a query).
     */
    public function getProviderAttribute()
    {
        // Prefer explicit provider attached via providerable pivot
        $provider = $this->providers()->first();
        if ($provider) return $provider;

        // Fallback: derive provider from the network's default network type/provider
        try {
            $network = Network::where('name', $this->network)->first();
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
        // True if an explicit provider is attached via providerable pivot
        if ($this->relationLoaded('providers')) {
            return !empty($this->getRelation('providers'));
        }

        return $this->providers()->exists();
    }

    protected function getPriceNgnAttribute()
    {
        return '₦' . number_format($this->price, 2);
    }

}
