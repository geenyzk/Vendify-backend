<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class Discount extends Model
{
    //
    protected $hidden = ["created_at", "updated_at"];
    protected $appends = ["price"];
    protected $casts = [
        "isActive" => 'boolean'
    ];
    protected static function booted()
    {
        static::retrieved(function ($model) {
            $model->network =$model->name;
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
        $array['network'] =$array['name'];

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


    function scopeAirtime(){
        return $this->where("type", "airtime")
        ->get()->map(function($airtime){
            $airtime->network = $airtime->name;
            return $airtime;
        });
    }


    public function getPriceAttribute(){
        $user = Auth::user();
        return $this->{$user?->user_type . "_discount"};
    }

    function scopeGetElectricity($query, $name){
        return $query->where("name", $name)
        ->first();
    }

    function scopeGetAllElectricity(){
        return $this->where("type", "electricity")
        ->get();
    }


   public static function getAmountRangeError(float $amount, string $category): ?string
    {
        $discount = self::where("category", $category)->first();

        if (!$discount) {
            return "Invalid category.";
        }

        if ($amount < $discount->min || $amount > $discount->max) {
            return "Amount must be between {$discount->min} and {$discount->max}.";
        }

        return null; // no error
    }


    /**
 * Calculate the discounted amount based on the original amount.
 */
    static public function getDiscountedAmount(float $amount, string $name): float
    {
        $discount = self::
        where("name", $name)
        ->orWhere("category", $name)
        ->first();
        $user = Auth::user();
        $user_discount_percent = $discount->{$user?->user_type . "_discount"};

        return round($amount - (($user_discount_percent / 100) * $amount), 2);
    }



}
