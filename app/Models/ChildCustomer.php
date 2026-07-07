<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChildCustomer extends Model
{
    protected $fillable = [
        'child_instance_id',
        'external_id',
        'username',
        'email',
        'phone',
        'wallet_balance',
        'status',
        'migrated_to_user_id',
    ];

    protected $casts = [
        'wallet_balance' => 'decimal:2',
    ];

    public function childInstance(): BelongsTo
    {
        return $this->belongsTo(ChildInstance::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(ChildTransaction::class);
    }

    public function migratedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'migrated_to_user_id');
    }
}
