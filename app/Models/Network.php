<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\NetworkType;

class Network extends Model
{
    protected $fillable = [
        'name', 'airtime_api_id', 'data_api_id', 'airtime_recharge_api_id', 'data_recharge_api_id',
        'airtime_to_cash_destination_number', 'airtime_to_cash_min', 'airtime_to_cash_max', 'airtime_to_cash_active',
    ];

    protected $casts = [
        'airtime_to_cash_min' => 'decimal:2',
        'airtime_to_cash_max' => 'decimal:2',
        'airtime_to_cash_active' => 'boolean',
    ];

    // Automatically eager load relationships when querying
    protected $with = ['networkTypes'];

    public function networkTypes()
    {
        return $this->belongsToMany(
            NetworkType::class, 
            'network_network_type', 
            'network_id', 
            'network_type_id'
        )->withPivot('service_type', 'active')->withTimestamps();
    }

    public function getTypeAttribute(){
        return $this->networkTypes()->pluck('name')->toArray();
    }
    
    public function getTypeIdsAttribute()
    {
        return $this->networkTypes()->pluck('id')->toArray();
    }
}
