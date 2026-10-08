<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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
        'account_name',
        'provider',
        'status',
        'amount',
        'ref',
        'tx_ref',
        'currency',
        'failure_reason',
        'expired_at',
    ];

    /**
     * Only accounts whose identifying details can actually be shown and used.
     * Some failed provider attempts left empty strings rather than nulls, so a
     * null-only check can incorrectly present an incomplete row as "active".
     */
    public function scopeWithUsableAccountDetails(Builder $query): Builder
    {
        return $query
            ->whereNotNull('bank_account')
            ->whereRaw('LENGTH(TRIM(bank_account)) > 0')
            ->whereNotNull('bank_name')
            ->whereRaw('LENGTH(TRIM(bank_name)) > 0');
    }

    function getLogoAttribute(){
        return url("/images/".str_replace(" ", "_", Str::lower($this->bank_name)) . ".png");
    }

    function getChargeAttribute(){
        $provider = Provider::whereName($this->provider)->first(['charge_fee', 'charge_type']);
        // Bank rows can outlive a provider configuration (or be created while
        // provisioning is still in progress). Do not let serialization of a
        // user payload crash on a missing provider.
        if (!$provider) {
            return null;
        }
        return $provider->charge_type == 'fiat' ?"NGN" . $provider->charge_fee :$provider->charge_fee ."%";
    }
}
