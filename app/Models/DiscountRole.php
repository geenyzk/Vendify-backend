<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiscountRole extends Model
{
    protected $table = 'discount_role';

    protected $fillable = ['discount_id', 'role_id', 'discount'];

    protected $casts = [
        'discount' => 'decimal:2',
    ];

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
