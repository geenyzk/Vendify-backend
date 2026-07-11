<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Sim extends Model
{
    protected $fillable = [
        'sim_device_id',
        'slot_index',
        'network',
        'phone_number',
        'supports_airtime',
        'supports_data',
        'airtime_balance',
        'data_balance_mb',
        'airtime_low_threshold',
        'data_low_threshold_mb',
        'enabled',
        'balance_reported_at',
        'notes',
    ];

    protected $casts = [
        'supports_airtime' => 'boolean',
        'supports_data' => 'boolean',
        'enabled' => 'boolean',
        'airtime_balance' => 'float',
        'data_balance_mb' => 'float',
        'airtime_low_threshold' => 'float',
        'data_low_threshold_mb' => 'float',
        'balance_reported_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(SimDevice::class, 'sim_device_id');
    }

    /**
     * SIMs that can fulfil a vend right now: enabled, right network,
     * service supported, last-reported stock covers the need, and the
     * host device is online. $need is naira for airtime, MB for data.
     */
    public function scopeEligible(Builder $query, string $service, string $network, ?float $need = null): Builder
    {
        $query->where('enabled', true)
            ->whereRaw('LOWER(network) = ?', [strtolower(trim($network))])
            ->where($service === 'data' ? 'supports_data' : 'supports_airtime', true)
            ->whereHas('device', fn (Builder $q) => $q->online());

        if ($need !== null) {
            $query->where($service === 'data' ? 'data_balance_mb' : 'airtime_balance', '>=', $need);
        }

        return $query;
    }
}
