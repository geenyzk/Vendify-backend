<?php

namespace App\Models;

use App\Classes\Payment\PaymentFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class Provider extends Model
{
    //
    protected $fillable = ["id", "name", "code", "base_url", "username", "password", "identifier", "sub_category", "category", "api_key", "public_key", "secret_key", "encryption_key", "webhook_access", "charge_fee", "charge_type", "withdrawal_fee", "withdrawal_fee_type", "active",
        "auto_fund_enabled", "auto_fund_threshold", "auto_fund_amount", "account_number", "account_name", "bank_code", "bank_name", "funding_provider_id"];
    protected $appends = ["webhook", "connection", "balance"];
    protected $casts = ["active" => "boolean", "auto_fund_enabled" => "boolean"];

    function scopeGetPaymentProviders($query) {
        $query
        ->whereIn("name", function($subQuery){
            $subQuery->select("name")
            ->from("service_controls")
            ->where("isActive", true)
            ->where("isDevLock", false);
        });
        return $query;
    }

    function getWebhookAttribute(){
        return $this->identifier ?url("/api/webhook/" . $this->sub_category ."/" . $this->identifier): '';
    }

    public function getConnectionAttribute(): mixed
    {
        if ($this->category !== 'payment') {
            return null;
        }

        try {
            return Cache::remember("payment_provider_connection_{$this->id}", now()->addMinutes(5), function () {
                return PaymentFactory::make($this)->connect();
            });
        } catch (\Throwable $e) {
            Log::warning('Payment provider connection lookup failed', [
                'provider_id' => $this->id,
                'provider' => $this->name,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function getBalanceAttribute(): mixed
    {
        if ($this->category !== 'payment') {
            return null;
        }

        try {
            return Cache::remember("payment_provider_balance_{$this->id}", now()->addMinutes(5), function () {
                return PaymentFactory::make($this)->checkBalance();
            });
        } catch (\Throwable $e) {
            Log::warning('Payment provider balance lookup failed', [
                'provider_id' => $this->id,
                'provider' => $this->name,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Data plans supplied by this provider
     */
    public function dataPlans()
    {
        // Provider can be attached to multiple "providerable" models; expose DataPlan specifically
        return $this->morphedByMany(DataPlan::class, 'providerable', 'providerables', 'provider_id', 'providerable_id')
            ->withPivot(['cost_price', 'margin_value', 'margin_type'])
            ->withTimestamps();
    }
}
