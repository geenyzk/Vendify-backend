<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Event extends Model
{
    protected $fillable = [
        'name',
        'description',
        'metric',
        'service_type',
        'threshold',
        'repeatable',
        'reward_type',
        'badge_name',
        'badge_icon',
        'cash_amount',
        'active',
    ];

    protected $casts = [
        'threshold' => 'decimal:2',
        'cash_amount' => 'decimal:2',
        'repeatable' => 'boolean',
        'active' => 'boolean',
    ];

    public function awards(): HasMany
    {
        return $this->hasMany(EventAward::class);
    }
}
