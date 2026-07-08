<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashbackRate extends Model
{
    protected $fillable = ['service_type', 'percentage', 'active'];

    protected $casts = [
        'percentage' => 'decimal:2',
        'active' => 'boolean',
    ];
}
