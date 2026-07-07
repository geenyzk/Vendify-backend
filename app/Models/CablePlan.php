<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class CablePlan extends Model
{
    //
    protected $appends = ["status", "price"];
    protected $casts = [
        "active" => "boolean"
    ];

     protected static function booted()
    {
        static::retrieved(function ($model) {
            if (env('APP_TYPE', "standalone") === 'affiliate') {
                foreach (range(2, 5) as $i) {
                    unset(
                        $model->{"adex_server_$i"},
                        $model->{"spurs_server_$i"},
                        $model->{"msorg_server_$i"}
                    );
                }

                unset($model->spurs_server_1, $model->msorg_server_1, $model->vtpass, $model->payscribe);
            }
        });
    }



     protected $fillable = [
        'cable_network', 'plan_name', 'active', 'user_price', 'bonanza_price',
        'agent_price', 'api_price',
        'adex_server_1', 'adex_server_2', 'adex_server_3', 'adex_server_4', 'adex_server_5',
        'spurs_server_1', 'spurs_server_2', 'spurs_server_3', 'spurs_server_4', 'spurs_server_5',
        'msorg_server_1', 'msorg_server_2', 'msorg_server_3', 'msorg_server_4', 'msorg_server_5',
        'vtpass', 'payscribe',
    ];

      public function getStatusAttribute(){
        return $this->active ? "active": 'inactive';
    }


    public function getPriceAttribute(){
        $user = Auth::user();
        return $this->{$user->pricingTier() . "_price"};
    }

}
