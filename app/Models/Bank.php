<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class Bank extends Model
{
    //

    protected $appends = ["charge", "logo"];
    protected $fillable = [
        'user_id',
        'account_type',
        'bank_account',
        'bank_name',
        'provider',
        'status',
        'amount',
        'ref',
        'tx_ref',
        'expired_at',
    ];

    function getLogoAttribute(){
        return url("/images/".str_replace(" ", "_", Str::lower($this->bank_name)) . ".png");
    }

    function getChargeAttribute(){
        $provider = Provider::whereName($this->provider)->first(['charge_fee', 'charge_type']);
        return $provider->charge_type == 'fiat' ?"NGN" . $provider->charge_fee :$provider->charge_fee ."%";
    }
}
