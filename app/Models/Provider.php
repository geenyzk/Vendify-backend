<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Provider extends Model
{
    //
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
}
