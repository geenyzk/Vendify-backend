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

    protected $appends = ['connection', 'balance', 'webhook'];

    protected $fillable = [
        'name', 'code', 'base_url', 'username', 'password', 'api_key',
        'auth_type', 'identifier', 'category', 'sub_category',
        'charge_fee', 'charge_type', 'webhook_access', 'active',
        'auto_fund_enabled', 'auto_fund_threshold', 'auto_fund_amount',
        'account_number', 'account_name', 'bank_code', 'bank_name',
        'funding_provider_id',
    ];

    protected $casts = [
        'auto_fund_enabled'  => 'boolean',
        'auto_fund_threshold' => 'float',
        'auto_fund_amount'   => 'float',
        'active'             => 'boolean',
    ];

    protected static function booted()
    {
        static::addGlobalScope('vendorOnly', function (Builder $builder) {
            $builder->where('category', 'vendor');
        });

        // The global scope only filters READS — a row created through this
        // model without an explicit category landed as NULL and instantly
        // vanished from every Vendor query (the create-a-vendor form 404'd
        // on its own result). Creating through Vendor MEANS category=vendor.
        static::creating(function (Vendor $vendor) {
            if (empty($vendor->category)) {
                $vendor->category = 'vendor';
            }
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
        try {
            $key = md5($this->base_url . $this->username . $this->password."-123");
            $provider = VendorFactory::make($this);
            return Cache::remember($key, now()->addMinutes(5), function() use($provider) {
                return $provider->isHealthy();
            });
        } catch (\Throwable $e) {
            // sub_category/name combinations not wired into VendorFactory yet
            // (e.g. "misc", "payment") shouldn't crash the whole list.
            Log::warning('Vendor connection lookup failed', [
                'vendor_id' => $this->id,
                'sub_category' => $this->sub_category,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function getBalanceAttribute()
    {
        try {
            $key = md5($this->base_url . $this->username . $this->password ."_balance");
            $provider = VendorFactory::make($this);
            return Cache::remember($key, now()->addMinutes(60), function() use($provider) {
                return $provider->checkBalance();
            });
        } catch (\Throwable $e) {
            Log::warning('Vendor balance lookup failed', [
                'vendor_id' => $this->id,
                'sub_category' => $this->sub_category,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }


    /**
     * Filters to the vendor row assigned to $service in Stock Vending (e.g.
     * "vtu", "sme", "dstv") — the admin-facing "which vendor fulfils this"
     * setting.
     *
     * Previously called `$this->where(...)->first()` inside the scope body:
     * `$this` there is the Vendor MODEL instance, not the query builder Eloquent
     * hands scopes ($query) — so that ran an entirely separate, throwaway
     * query, then returned it. Eloquent's scope mechanism ignores a scope's
     * return value for chaining purposes and always hands back $query
     * unmodified, so every `Vendor::provider($service)->first()` call
     * silently returned the first row of the whole `providers` table —
     * Stock Vending's per-service assignment had zero effect on which
     * vendor actually processed a purchase. Constraining $query itself
     * (and returning it) is what makes this scope, and its ->first()/->get()
     * chaining, do what it looks like it does.
     */
    public function scopeProvider(Builder $query, string $service)
    {
        $stock = StockVending::first();
        if (!$stock || !isset($stock->{$service})) {
            return $query->whereRaw('0 = 1');
        }

        // stock_vendings' own migration defaults every column to
        // "adex_server_1" (underscored), but real provider rows are named
        // "adex server 1" (spaced) — normalize both sides so that mismatch
        // (and any future case/spacing slip, since this has no admin UI to
        // manage it and is edited only via raw table writes today) doesn't
        // silently resolve to "no vendor found".
        $normalized = strtolower(str_replace('_', ' ', $stock->{$service}));

        return $query->whereRaw('LOWER(REPLACE(name, "_", " ")) = ?', [$normalized]);
    }



    function getWebhookAttribute(){
        return $this->identifier ?url("/api/webhook/" . $this->sub_category ."/" . $this->identifier): '';
    }

    public function fundingProvider()
    {
        return $this->belongsTo(Provider::class, 'funding_provider_id');
    }

    public function vendorFundings()
    {
        return $this->hasMany(VendorFunding::class, 'vendor_id');
    }

    public function networkServices()
    {
        return $this->hasMany(NetworkType::class, 'provider_id');
    }

    /**
     * Data plans supplied by this vendor (same table as providers)
     */
    public function dataPlans()
    {
        return $this->morphedByMany(DataPlan::class, 'providerable', 'providerables', 'provider_id', 'providerable_id')
            ->withPivot(['cost_price', 'margin_value', 'margin_type', 'server_id'])
            ->withTimestamps();
    }
}

