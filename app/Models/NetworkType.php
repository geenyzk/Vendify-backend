<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use App\Models\Network;
use Illuminate\Support\Facades\DB;
use App\Support\PerformanceCache;

class NetworkType extends Model
{
    use Auditable;
    protected $fillable = ['name', 'active', 'service_type', 'provider_id'];
    protected $with = ['provider'];

    protected static function boot()
    {
        parent::boot();

        // When a network type is created, create a pivot entry with null network_id
        // static::created(function ($networkType) {
        //     // Get the service_type from the request context (stored in a temporary dynamic property or attribute)
        //     $serviceType = $networkType->_service_type ?? $networkType->getAttribute('_service_type') ?? 'airtime';

        //     // Insert into network_network_type pivot table with null network_id
        //     // This creates a "base" pivot entry that will later be linked to actual networks
        //     DB::table('network_network_type')->insertOrIgnore([
        //         'network_id' => null,
        //         'network_type_id' => $networkType->id,
        //         'service_type' => $serviceType,
        //         'created_at' => now(),
        //         'updated_at' => now(),
        //     ]);
        // });
        // When a network type is updated, propagate its service_type to pivot rows
        static::updated(function ($networkType) {
            try {
                DB::table('network_network_type')
                    ->where('network_type_id', $networkType->id)
                    ->update([
                        'service_type' => $networkType->service_type,
                        'updated_at' => now(),
                    ]);
            } catch (\Exception $e) {
                // swallow; controller will log if needed
            }

            if ($networkType->service_type === 'data' && $networkType->wasChanged('name')) {
                DB::table('data_plans')
                    ->where('network_type_id', $networkType->id)
                    ->update(['plan_type' => $networkType->name, 'updated_at' => now()]);
                PerformanceCache::clearCatalog();
            }
        });
    }

    public function networks()
    {
        return $this->belongsToMany(
            Network::class,
            'network_network_type',
            'network_type_id',
            'network_id'
        )->withPivot('service_type', 'active')->withTimestamps();
    }

    public function provider(){
        return $this->belongsTo(Vendor::class, 'provider_id');
    }

    public function dataPlans()
    {
        return $this->hasMany(DataPlan::class, 'network_type_id');
    }
}
