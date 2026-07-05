<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payee extends Model
{
    protected $fillable = [
        'name', 'type', 'bank_name', 'account_number', 'account_name',
        'payout_type', 'payout_value', 'frequency', 'active', 'notes',
    ];

    protected $casts = [
        'active' => 'boolean',
        'payout_value' => 'decimal:2',
    ];
}
