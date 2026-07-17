<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AuthSession extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id', 'channel', 'laravel_session_id', 'access_token_id',
        'device_id', 'device_name', 'device_type', 'platform', 'browser',
        'ip_address', 'approximate_location', 'last_active_at',
        'idle_expires_at', 'absolute_expires_at', 'reauthenticated_at',
        'revoked_at', 'revocation_reason',
    ];

    protected $casts = [
        'last_active_at' => 'datetime',
        'idle_expires_at' => 'datetime',
        'absolute_expires_at' => 'datetime',
        'reauthenticated_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function refreshTokens(): HasMany
    {
        return $this->hasMany(AuthRefreshToken::class);
    }
}
