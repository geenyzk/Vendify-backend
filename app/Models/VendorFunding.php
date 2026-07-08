<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VendorFunding extends Model
{
    protected $fillable = [
        'vendor_id',
        'payment_provider_id',
        'amount',
        'reference',
        'status',
        'balance_before',
        'gateway_response',
    ];

    protected $casts = [
        'gateway_response' => 'array',
        'amount' => 'float',
        'balance_before' => 'float',
    ];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function paymentProvider()
    {
        return $this->belongsTo(Provider::class, 'payment_provider_id');
    }
}
