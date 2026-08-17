<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BettingProvider extends Model
{
    protected $fillable = [
        'name', 'slug', 'provider_code', 'biller_id', 'active',
        'verification_supported', 'minimum_amount', 'maximum_amount',
        'flat_fee', 'percentage_fee', 'metadata',
    ];

    protected $casts = [
        'active' => 'boolean',
        'verification_supported' => 'boolean',
        'minimum_amount' => 'decimal:2',
        'maximum_amount' => 'decimal:2',
        'flat_fee' => 'decimal:2',
        'percentage_fee' => 'decimal:4',
        'metadata' => 'array',
    ];

    public function chargeFor(float $amount): float
    {
        return round((float) $this->flat_fee + ($amount * (float) $this->percentage_fee / 100), 2);
    }
}
