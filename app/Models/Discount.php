<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Discount extends Model
{
    protected $hidden = ["created_at", "updated_at"];

    protected $fillable = [
        "name", "service_type", "network", "discount_type", "value",
        "min", "max", "active", "starts_at", "ends_at",
    ];

    protected $casts = [
        "value" => "decimal:2",
        "active" => "boolean",
        "starts_at" => "date",
        "ends_at" => "date",
    ];

    /**
     * Whether this discount is currently live: the active flag is on and —
     * if a window is set — today falls inside it. A discount with no
     * window (both null) is always-on whenever active.
     */
    public function isCurrentlyActive(): bool
    {
        if (!$this->active) return false;

        $today = now()->startOfDay();

        if ($this->starts_at && $today->lt($this->starts_at)) return false;
        if ($this->ends_at && $today->gt($this->ends_at)) return false;

        return true;
    }

    /**
     * Calculate the discounted amount for a purchase. Returns the original
     * amount unchanged if no active discount applies.
     */
    public static function getDiscountedAmount(float $amount, string $serviceType, ?string $network = null): float
    {
        $discount = static::findApplicable($serviceType, $network, $amount);

        if (!$discount) {
            return $amount;
        }

        $value = (float) $discount->value;
        $reduction = $discount->discount_type === 'fixed'
            ? min($value, $amount)
            : $amount * ($value / 100);

        return round(max(0, $amount - $reduction), 2);
    }

    /**
     * Pick the best-matching active discount for a purchase: a rule scoped
     * to this exact network wins over a network-agnostic rule (network is
     * null, i.e. applies to every network) for the same service type. Only
     * rows currently live (see isCurrentlyActive) and within the amount's
     * min/max range (if set) are considered.
     */
    protected static function findApplicable(string $serviceType, ?string $network, float $amount): ?self
    {
        $candidates = static::where('service_type', $serviceType)
            ->where(function ($q) use ($network) {
                $q->whereNull('network');
                if ($network) {
                    $q->orWhere('network', $network);
                }
            })
            ->get()
            ->filter(fn (self $d) => $d->isCurrentlyActive())
            ->filter(function (self $d) use ($amount) {
                if ($d->min !== null && $amount < $d->min) return false;
                if ($d->max !== null && $amount > $d->max) return false;
                return true;
            });

        if ($candidates->isEmpty()) {
            return null;
        }

        return $candidates->first(fn (self $d) => $network && $d->network === $network)
            ?? $candidates->first();
    }
}
