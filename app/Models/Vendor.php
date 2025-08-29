<?php

namespace App\Models;

use App\Classes\Vendor\VendorFactory;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class Vendor extends Model
{
    //
    protected $table = 'providers';

    protected $appends = ['connection', 'balance', "webhook"];

    protected static function booted()
    {
        static::addGlobalScope('vendorOnly', function (Builder $builder) {
            $builder->where('category', 'vendor');
        });
    }

    public function newQuery()
    {
        $query = parent::newQuery();

        $app_type = env('APP_TYPE', "standalone");
        $type = (boolean) (env('APP_TYPE', "standalone") === "affiliate");
        if ($type) {
            $query->limit(1);
        }

        return $query;
    }

    public function getConnectionAttribute()
    {
        $key = md5($this->base_url . $this->username . $this->password."-123");
        $provider = VendorFactory::make($this);
        return Cache::remember($key, now()->addMinutes(5), function() use($provider) {
            return $provider->isHealthy();
        });
    }

    public function getBalanceAttribute()
    {
        $key = md5($this->base_url . $this->username . $this->password ." balance");
        $provider = VendorFactory::make($this);
        return Cache::remember($key, now()->addMinutes(60), function() use($provider) {
            return $provider->checkBalance();
        });
    }


    public function scopeProvider(Builder $query, string $service)
    {
        $stock = StockVending::first();
        if (!$stock || !isset($stock->{$service})) {
            return null;
        }

        $providerName = $stock->{$service};
        $q = $this->where('name', $providerName)->first();
        return $q;
    }



    function getWebhookAttribute(){
        return $this->identifier ?url("/api/webhook/" . $this->sub_category ."/" . $this->identifier): '';
    }
}

