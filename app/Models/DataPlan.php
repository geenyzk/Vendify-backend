<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasProviderFallback;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DataPlan extends Model
{
    use Auditable;
    use HasProviderFallback;

    protected $appends = ['price_ngn', 'plan', 'status', 'price', 'provider', 'use_provider_as_providerable', 'fallbacks', 'fallback_provider', 'fallback_provider_id', 'fallback_server_id', 'fallback_cost_price'];

    protected $fillable = [
        'id',
        'plan_name',
        'plan_size',
        'plan_type',
        'server_id',
        'network',
        'active',
        'is_draft',
        'validity',
        'sort_order',
        'pricing',
    ];

    protected $casts = [
        'active' => 'boolean',
        'is_draft' => 'boolean',
        'pricing' => 'array',
    ];

    // Note: Avoid overriding `save()` to prevent infinite recursion or unexpected
    // behavior. Relationship syncs (providerable) are handled in the
    // `AdminController::syncModelRelations` logic when creating/updating via API.
    protected static function booted()
    {
        static::saving(function (DataPlan $plan) {
            if (! $plan->exists || ! $plan->active || ! Schema::hasColumn('providerables', 'external_plan_id')) {
                return;
            }

            $unpricedImportedPlan = DB::table('providerables')
                ->where('providerable_id', $plan->getKey())
                ->where('providerable_type', self::class)
                ->whereNotNull('external_plan_id')
                ->where('cost_price', '<=', 0)
                ->exists();

            if ($unpricedImportedPlan) {
                $plan->active = false;
                $plan->is_draft = true;
            }
        });

        static::retrieved(function ($model) {
            if (env('APP_TYPE', 'standalone') === 'affiliate') {
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

        if (env('APP_TYPE', 'standalone') === 'affiliate') {
            // Keep only adex_server_1, remove others
            foreach (range(2, 5) as $i) {
                unset(
                    $array["adex_server_$i"],
                    $array["spurs_server_$i"],
                    $array["msorg_server_$i"]
                );
            }

            unset($array['spurs_server_1'], $array['msorg_server_1'], $array['vtupass'], $array['payscribe']);
        }

        return $array;
    }

    public function getPlanAttribute()
    {
        return $this->plan_name.$this->plan_size;
    }

    public function getStatusAttribute()
    {
        return $this->active ? 'active' : 'inactive';
    }

    public function getPriceAttribute(): ?float
    {
        $user = Auth::user();
        $role = $user?->role;
        // New plans created by the admin form are keyed by role name, while
        // older records and legacy price columns commonly use role.slug or
        // user_type. Resolve all stable identifiers before falling back to
        // the base customer price so a renamed/display-cased role does not
        // make an otherwise sellable plan look unpriced.
        $roleKeys = array_values(array_unique(array_filter([
            $role?->name,
            $role?->slug,
            $user?->user_type,
            'user',
        ], fn ($key) => is_string($key) && $key !== '')));

        // Prefer a role-specific JSON entry, accepting the display name,
        // slug, or legacy user_type. Roles added after a plan was priced use
        // the customer/user configuration where one exists rather than an
        // invented zero price.
        if (is_array($this->pricing)) {
            $entry = $this->pricingEntryFor($roleKeys);

            if ($entry !== null) {
                // New shape: ["type" => "fiat"|"percentage", "value" => number].
                // "percentage" is a markup over cost price: cost + cost * value%.
                if (is_array($entry)) {
                    if (! array_key_exists('value', $entry) || ! is_numeric($entry['value'])) {
                        return null;
                    }

                    $value = (float) $entry['value'];
                    if (($entry['type'] ?? 'fiat') === 'percentage') {
                        $cost = $this->resolveCostPrice();

                        return $cost > 0 ? round($cost * (1 + $value / 100), 2) : null;
                    }

                    return $value;
                }

                // Legacy JSON shape: a plain fiat number.
                return is_numeric($entry) ? (float) $entry : null;
            }
        }

        // Fallback to legacy per-role columns in the same order. A role name
        // can contain spaces, so only slug-like keys are valid column names.
        foreach ($roleKeys as $key) {
            $column = preg_replace('/[^A-Za-z0-9_]/', '_', $key) . '_price';
            $legacyPrice = $this->getAttribute($column);
            if ($legacyPrice !== null && is_numeric($legacyPrice)) {
                return (float) $legacyPrice;
            }
        }

        return null;
    }

    /** @param array<int, string> $roleKeys */
    private function pricingEntryFor(array $roleKeys): mixed
    {
        foreach ($roleKeys as $key) {
            if (array_key_exists($key, $this->pricing)) {
                return $this->pricing[$key];
            }
        }

        // Older imports can differ only by case (e.g. Agent vs agent).
        foreach ($roleKeys as $key) {
            foreach ($this->pricing as $configuredRole => $entry) {
                if (is_string($configuredRole) && strcasecmp($configuredRole, $key) === 0) {
                    return $entry;
                }
            }
        }

        return null;
    }

    /**
     * The provider's cost price for this plan — the base that a
     * "percentage" pricing entry marks up from. Prefers the loaded
     * providers() pivot, falling back to a direct providerables lookup
     * (mirrors DataPlanResource's own fallback).
     */
    public function resolveCostPrice(): float
    {
        $provider = $this->relationLoaded('providers')
            ? $this->getRelation('providers')->first()
            : $this->providers()->first();

        $pivotCost = $provider?->pivot?->cost_price;
        if ($pivotCost !== null) {
            return (float) $pivotCost;
        }

        // The providers() join can miss a providerable row whose provider_id
        // no longer exists (a deleted provider) — the raw fallback below
        // catches that. But when a caller explicitly eager-loaded the
        // relation (e.g. the admin data-plan list, which batches it in one
        // query across every row), an empty result is already authoritative:
        // re-querying per row here is exactly the N+1 that eager load was
        // meant to avoid, for an edge case eager loading already resolved.
        // Callers that never eager-load (e.g. the purchase flow's
        // DataPlan::find()) are unaffected and keep the raw-query fallback.
        if ($this->relationLoaded('providers')) {
            return 0.0;
        }

        $row = DB::table('providerables')
            ->where('providerable_id', $this->id)
            ->where('providerable_type', self::class)
            ->first();

        return (float) ($row->cost_price ?? 0);
    }

    public function getNetworkAttribute($value)
    {
        // The public API has historically exposed lower-case network slugs
        // (mtn, airtel, glo, 9mobile). Keep that stable for catalogue filters,
        // icons and purchase requests while allowing canonical labels in DB.
        return strtolower((string) $value);
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
            ->withPivot(['cost_price', 'margin_value', 'margin_type', 'server_id', 'external_plan_id'])
            ->withTimestamps();
    }

    /**
     * Convenience accessor: return the single provider for this data plan.
     * Access via `$dataPlan->provider` (will execute a query).
     */
    public function getProviderAttribute()
    {
        // Prefer explicit provider attached via providerable pivot
        $provider = $this->relationLoaded('providers')
            ? $this->getRelation('providers')->first()
            : $this->providers()->first();

        if ($provider) {
            return $provider;
        }

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
            return $this->getRelation('providers')->isNotEmpty();
        }

        return $this->providers()->exists();
    }

    /**
     * The Vendor that should fulfil a purchase of this data plan: the provider
     * explicitly attached via the Data Plan form's custom-provider toggle (the
     * `providerables` pivot). Returns null when no plan-specific provider is
     * set, so callers fall back to the Stock Vending assignment — mirrors
     * AirtimePlan::resolveVendor().
     *
     * `providers()` yields a Provider (a raw `providers` row with no scope);
     * re-resolve it as a Vendor so it carries the category=vendor global scope
     * that VendorFactory::make() and the vendor classes expect.
     */
    public function resolveVendor(): ?Vendor
    {
        $provider = $this->providers()->first();
        if (! $provider) {
            return null;
        }

        return Vendor::find($provider->id);
    }

    protected function getPriceNgnAttribute()
    {
        $price = $this->price;

        return $price === null ? null : '₦'.number_format($price, 2);
    }
}
