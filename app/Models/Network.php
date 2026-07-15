<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use App\Models\NetworkType;

class Network extends Model
{
    use Auditable;
    protected $fillable = [
        'name', 'active', 'airtime_api_id', 'data_api_id', 'airtime_recharge_api_id', 'data_recharge_api_id',
        'airtime_to_cash_destination_number', 'airtime_to_cash_min', 'airtime_to_cash_max', 'airtime_to_cash_active',
    ];

    protected $casts = [
        'active' => 'boolean',
        'airtime_to_cash_min' => 'decimal:2',
        'airtime_to_cash_max' => 'decimal:2',
        'airtime_to_cash_active' => 'boolean',
    ];

    // Expose the admin table's "status" ("active"/"inactive") off the boolean
    // column, mirroring DataPlan/CablePlan — without this the frontend read an
    // undefined `status` and StatusBadge always rendered "pending".
    protected $appends = ['status'];

    // Automatically eager load relationships when querying
    protected $with = ['networkTypes'];

    public function getStatusAttribute()
    {
        return $this->active ? 'active' : 'inactive';
    }

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
