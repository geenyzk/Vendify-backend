<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Provider extends Model
{
    //
    protected $fillable = ["id", "name", "base_url", "username", "password", "identifier", "sub_category"];
    protected $appends = ["webhook"];

    function scopeGetPaymentProviders($query) {
        $query
        ->whereIn("name", function($subQuery){
            $subQuery->select("name")
            ->from("service_controls")
            ->where("isActive", true)
            ->where("isDevLock", false);
        });
        return $query;
    }

    function getWebhookAttribute(){
        return $this->identifier ?url("/api/webhook/" . $this->sub_category ."/" . $this->identifier): '';
    }

    /**
     * Data plans supplied by this provider
     */
    public function dataPlans()
    {
        // Provider can be attached to multiple "providerable" models; expose DataPlan specifically
        return $this->morphedByMany(DataPlan::class, 'providerable', 'providerables', 'provider_id', 'providerable_id')
            ->withPivot(['cost_price', 'margin_value', 'margin_type'])
            ->withTimestamps();
    }
}
