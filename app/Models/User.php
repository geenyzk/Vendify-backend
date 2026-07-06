<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $fillable = [
        'username', 'fullname', 'email', 'phone', 'password', 'status',
        'user_type', 'wallet_balance', 'is_active', 'is_verified',
        'referral_code', 'referred_by', 'last_login_at',
    ];

    protected $appends  = ["transactions", "banks", "stats", "referrals"];
    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'wallet_balance' => 'decimal:2',
        'is_active' => 'boolean',
        'is_verified' => 'boolean',
        'last_login_at' => 'datetime',
    ];



    public function getReferralsAttribute()
    {
        return User::whereReferredBy($this->id)->get();
    }


    function getTransactionsAttribute(){
        return Transaction::where("user_id", $this->id)->get();
    }

    function getStatsAttribute(){

        $transaction = Transaction::where("user_id", $this->id);
        return [
            "daily_purchased_data" => $transaction
            ->whereTransactionType("data_subscription")
            ->whereStatus("success")
            ->whereMonth("created_at", now()->month)
            ->whereYear("created_at", now()->year)->sum("quantity") . "GB",
            "monthly_tx" => $transaction
            ->whereMonth("created_at", now()->month)
            ->whereYear("created_at", now()->year)
        ];
    }


    function getBanksAttribute($query){
        return Bank::where("user_id", $this->id)->get();
    }
}
