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
    ];

    protected $casts = [
        // Needed in plaintext to recompute the HMAC on each inbound
        // request — reversible encryption, not a one-way hash.
        'shared_secret' => 'encrypted',
        'last_seen_at' => 'datetime',
        'config' => 'array',
    ];

    protected $hidden = [
        'shared_secret',
    ];

    protected static function booted(): void
    {
        static::creating(function (ChildInstance $instance) {
            if (empty($instance->slug)) {
                $instance->slug = Str::slug($instance->name) . '-' . Str::lower(Str::random(6));
            }
            if (empty($instance->shared_secret)) {
                $instance->shared_secret = Str::random(64);
            }
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
}
