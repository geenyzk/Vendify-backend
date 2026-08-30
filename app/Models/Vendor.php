<?php

namespace App\Models;

use App\Classes\Vendor\VendorFactory;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class Vendor extends Model
{
    //
    protected $table = 'providers';

    protected $appends = ['connection', 'balance', 'webhook', 'plans_count', 'active_plans_count'];

    protected $hidden = ['password', 'api_key', 'secret_key', 'encryption_key'];

    protected $fillable = [
        'name', 'code', 'base_url', 'username', 'password', 'api_key', 'public_key',
        'auth_type', 'identifier', 'category', 'sub_category',
        'charge_fee', 'charge_fee_cap', 'charge_type', 'webhook_access', 'active',
        'auto_fund_enabled', 'auto_fund_threshold', 'auto_fund_amount',
        'account_number', 'account_name', 'bank_code', 'bank_name',
        'funding_provider_id', 'manual_balance',
    ];

    protected $casts = [
        'auto_fund_enabled'  => 'boolean',
        'auto_fund_threshold' => 'float',
        'auto_fund_amount'   => 'float',
        'manual_balance'     => 'float',
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

        static::saving(function (Vendor $vendor) {
            if ($vendor->sub_category === 'ogdams') {
                $vendor->sub_category = 'simhost';
            }

            // An unchecked/omitted webhook control can arrive from the
            // shared provider form as null. Preserve the existing value on
            // updates and use the database default on creates; an explicit
            // "0" must remain valid so disabled webhook access stays off.
            if (array_key_exists('webhook_access', $vendor->getAttributes())
                && $vendor->getAttribute('webhook_access') === null) {
                $vendor->setAttribute(
                    'webhook_access',
                    $vendor->exists
                        ? ($vendor->getRawOriginal('webhook_access') ?? '1')
                        : '1'
                );
            }
        });
    }

    public function newQuery()
    {
        $query = parent::newQuery();

        $app_type = env('APP_TYPE', "standalone");
        $type = (bool) (env('APP_TYPE', "standalone") === "affiliate");
        if ($type) {
            $query->limit(1);
        }

        return $query;
    }

    public function getConnectionAttribute()
    {
        $key = md5($this->base_url . $this->username . $this->password . '-123');
        $cached = Cache::get($key);
        if ($cached !== null) {
            return $cached;
        }

        $this->refreshMetricAfterResponse($key, 'health', fn () => VendorFactory::make($this)->isHealthy());

        // The saved switch is immediately useful while a live health result
        // is warming in the background.
        return (bool) $this->active;
    }

    public function getBalanceAttribute()
    {
        if ($this->manual_balance !== null && $this->manual_balance !== '') {
            return (float) $this->manual_balance;
        }

        $key = md5($this->base_url . $this->username . $this->password . '_balance');
        $cached = Cache::get($key);
        if ($cached !== null) {
            return $cached;
        }

        $this->refreshMetricAfterResponse($key, 'balance', fn () => VendorFactory::make($this)->checkBalance());

        return null;
    }

    private function refreshMetricAfterResponse(string $key, string $metric, callable $resolver): void
    {
        if (app()->environment('testing')) {
            return;
        }

        $vendorId = $this->id;
        $subCategory = $this->sub_category;
        app()->terminating(function () use ($key, $metric, $resolver, $vendorId, $subCategory) {
            try {
                Cache::lock("provider-metric:{$key}", 30)->get(function () use ($key, $resolver) {
                    if (Cache::get($key) === null) {
                        Cache::put($key, $resolver(), now()->addMinutes(3));
                    }
                });
            } catch (\Throwable $e) {
                Log::warning("Vendor {$metric} lookup failed", [
                    'vendor_id' => $vendorId,
                    'sub_category' => $subCategory,
                    'error' => $e->getMessage(),
                ]);
            }
        });
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
        if (!$stock) {
            return $query->whereRaw('0 = 1');
        }

        // The requested service arrives as free text: a data plan_type or
        // airtime category. Vendor sync stores uppercase codes ("SME",
        // "GIFTING") and callers pass "cooperate_gifting" while the actual
        // column is "cooperate gifting" — so a plain isset($stock->{$service})
        // missed every one of them and fell through to "no vendor found".
        // Match the requested service against the stock_vendings columns
        // case- and separator-insensitively before reading the assignment.
        $normalizeKey = fn (string $value): string => strtolower(str_replace(['_', '-'], ' ', trim($value)));
        $wanted = $normalizeKey($service);

        $column = null;
        foreach (array_keys($stock->getAttributes()) as $col) {
            if (in_array($col, ['id', 'created_at', 'updated_at'], true)) {
                continue;
            }
            if ($normalizeKey($col) === $wanted) {
                $column = $col;
                break;
            }
        }

        if ($column === null) {
            return $query->whereRaw('0 = 1');
        }

        // stock_vendings' own migration defaults every column to
        // "adex_server_1" (underscored), but real provider rows are named
        // "adex server 1" (spaced) — normalize both sides so that mismatch
        // (and any future case/spacing slip) doesn't silently resolve to
        // "no vendor found".
        $normalized = strtolower(str_replace('_', ' ', (string) $stock->{$column}));

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
        $fields = ['cost_price', 'margin_value', 'margin_type', 'server_id', 'external_plan_id'];
        if (Schema::hasColumn('providerables', 'provider_price')) {
            $fields[] = 'provider_price';
        }
        foreach (['provider_service_id', 'provider_plan_name', 'provider_available', 'provider_enabled', 'priority', 'last_synced_at'] as $field) {
            if (Schema::hasColumn('providerables', $field)) {
                $fields[] = $field;
            }
        }
        $relation = $this->morphedByMany(DataPlan::class, 'providerable', 'providerables', 'provider_id', 'providerable_id')
            ->withPivot($fields)->withTimestamps();

        return Schema::hasColumn('providerables', 'priority')
            ? $relation->orderBy('providerables.priority')
            : $relation;
    }

    public function getPlansCountAttribute(): int { return $this->dataPlans()->count(); }
    public function getActivePlansCountAttribute(): int { return $this->dataPlans()->where('data_plans.active', true)->count(); }
}
