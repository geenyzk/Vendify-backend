<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class DataPlan extends Model
{
    //
    protected $appends = ['plan', "status", "price"];
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


    public function toArray()
    {
        $array = parent::toArray();

        if (env('APP_TYPE', "standalone") === 'affiliate') {
            // Keep only adex_server_1, remove others
            foreach (range(2, 5) as $i) {
                unset(
                    $array["adex_server_$i"],
                    $array["spurs_server_$i"],
                    $array["msorg_server_$i"]
                );
            }

            unset($array["spurs_server_1"], $array["msorg_server_1"], $array["vtupass"], $array["payscribe"]);
        }

        return $array;
    }

    public function getPlanAttribute(){
        return $this->plan_name . $this->plan_size;
    }

     public function getStatusAttribute(){
        return $this->active ? "active": 'inactive';
    }


    public function getPriceAttribute(){
        $user = Auth::user();
        Log::info();
        return $this->{$user->user_type?? "user" . "_price"};
    }
    public function getNetworkAttribute($value)
    {
        return strtolower($value);
    }


}
