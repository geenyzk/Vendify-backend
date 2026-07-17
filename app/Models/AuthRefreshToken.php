<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuthRefreshToken extends Model
{
    protected $fillable = [
        'auth_session_id', 'token_hash', 'replaced_by_id', 'expires_at',
        'used_at', 'revoked_at',
    ];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function authSession(): BelongsTo
    {
        return $this->belongsTo(AuthSession::class);
    }
}
