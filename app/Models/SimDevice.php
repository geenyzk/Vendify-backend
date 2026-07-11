<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class SimDevice extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'shared_secret',
        'status',
        'last_seen_at',
        'app_version',
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
        static::creating(function (SimDevice $device) {
            if (empty($device->slug)) {
                $device->slug = Str::slug($device->name) . '-' . Str::lower(Str::random(6));
            }
            // shared_secret stays null until the device self-registers with
            // its one-time code (SimDeviceRegistrationController::register).
        });
    }

    public function sims(): HasMany
    {
        return $this->hasMany(Sim::class);
    }

    /**
     * A device counts as online when it has checked in (heartbeat or any
     * signed call) within the configured window — routing only dispatches
     * to SIMs whose host is online.
     */
    public function scopeOnline(Builder $query): Builder
    {
        return $query->where('status', 'active')
            ->where('last_seen_at', '>=', now()->subSeconds((int) config('simvending.online_window')));
    }

    public function isOnline(): bool
    {
        return $this->status === 'active'
            && $this->last_seen_at
            && $this->last_seen_at->gte(now()->subSeconds((int) config('simvending.online_window')));
    }
}
