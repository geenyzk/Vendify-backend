<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ChildInstance extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'base_url',
        'shared_secret',
        'status',
        'last_seen_at',
        'health_status',
        'config',
        'registration_code',
        'registration_code_expires_at',
        'registered_at',
    ];

    protected $casts = [
        // Needed in plaintext to recompute the HMAC on each inbound
        // request — reversible encryption, not a one-way hash.
        'shared_secret' => 'encrypted',
        'last_seen_at' => 'datetime',
        'config' => 'array',
        'registration_code_expires_at' => 'datetime',
        'registered_at' => 'datetime',
    ];

    protected $hidden = [
        'shared_secret',
        'registration_code',
    ];

    protected static function booted(): void
    {
        static::creating(function (ChildInstance $instance) {
            if (empty($instance->slug)) {
                $instance->slug = Str::slug($instance->name) . '-' . Str::lower(Str::random(6));
            }
            // shared_secret is intentionally NOT generated here anymore —
            // it stays null until the child completes self-registration
            // (see AdminController::generateChildRegistrationCode() /
            // ChildRegistrationController::register()). An instance created
            // directly with an explicit shared_secret (rare — e.g. a
            // one-off manual setup) is left alone.
        });
    }

    public function customers(): HasMany
    {
        return $this->hasMany(ChildCustomer::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(ChildTransaction::class);
    }

    public function directives(): HasMany
    {
        return $this->hasMany(ChildDirective::class);
    }

    public function virtualAccounts(): HasMany
    {
        return $this->hasMany(ChildVirtualAccount::class);
    }

    public function creditEvents(): HasMany
    {
        return $this->hasMany(ChildCreditEvent::class);
    }

    /**
     * Whether the parent aggregates this child's customer funding — generating
     * their virtual accounts and relaying credits. Driven by the affiliate
     * "Aggregate funding to this platform" toggle, stored in config.controls
     * (same place every other affiliate control lives).
     */
    public function fundingAggregationEnabled(): bool
    {
        return (bool) data_get($this->config, 'controls.funding_aggregation.enabled', false);
    }
}
