<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class BillPlan extends Model
{
    use Auditable;
    protected $fillable = ['disco', 'min', 'max', 'service_fee', 'active'];

    protected $appends = ['status', 'provider', 'use_provider_as_providerable'];

    protected $casts = [
        'active' => 'boolean',
        'service_fee' => 'array',
        'min' => 'decimal:2',
        'max' => 'decimal:2',
    ];

    public function getStatusAttribute()
    {
        return $this->active ? 'active' : 'inactive';
    }

    /**
     * The provider that supplies this disco, with its own numeric plan/disco
     * ID (pivot `server_id`) — the same polymorphic relation DataPlan/
     * CablePlan use. Unlike those, there's no `cost_price` here: electricity
     * is pay-any-amount, so there's no fixed cost to attribute to the
     * provider, only which vendor handles it and its ID for this disco.
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

    /**
     * The role-specific service fee added on top of whatever amount the
     * customer chooses to pay — electricity has no fixed catalog price
     * (unlike a data/cable plan), so this is computed against the actual
     * amount at purchase time rather than exposed as a plain attribute.
     */
    public function resolveServiceFee(float $amount): float
    {
        $user = Auth::user();
        $role = $user?->role->name ?? 'user';

        if (!is_array($this->service_fee) || !array_key_exists($role, $this->service_fee)) {
            return 0.0;
        }

        $entry = $this->service_fee[$role];
        $value = (float) ($entry['value'] ?? 0);

        return ($entry['type'] ?? 'fiat') === 'percentage'
            ? round($amount * ($value / 100), 2)
            : $value;
    }
}
