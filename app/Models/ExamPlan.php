<?php

namespace App\Models;

use App\HasServers;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ExamPlan extends Model
{
    /** @use HasFactory<\Database\Factories\ExamPlanFactory> */
    use HasFactory, HasServers;
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


    protected $appends = ["price"];

    protected $attribute = [
        'adex_server_1' => '0',
        'adex_server_2' => '0',
        'adex_server_3' => '0',
        'adex_server_4' => '0',
        'adex_server_5' => '0',
        'msorg_server_1' => '0',
        'msorg_server_2' => '0',
        'msorg_server_3' => '0',
        'msorg_server_4' => '0',
        'msorg_server_5' => '0',
        'spurs_server_1' => '0',
        'spurs_server_2' => '0',
        'spurs_server_3' => '0',
        'spurs_server_4' => '0',
        'spurs_server_5' => '0',
    ];

    public function getPriceAttribute(){
        $user = Auth::user();
        return $this->{($user?->pricingTier() ?? "user") . "_discount"} ?? 0;
    }
}
