<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Promotion extends Model
{
    use Auditable;
    use HasFactory;

    protected $table = 'promotions';

    protected $fillable = [
        'name',
        'code',
        'apply',
        'target',
        'targets',
        'product',
        'products',
        'provider',
        'type',
        'value',
        'active',
        'starts_at',
        'ends_at',
        'usage_limit_total',
        'usage_limit_per_customer',
        'used',
        'conditions',
    ];

    protected $casts = [
        'conditions' => 'array',
        'products' => 'array',
        'targets' => 'array',
        'active' => 'boolean',
        'starts_at' => 'date',
        'ends_at' => 'date',
        'value' => 'decimal:2',
    ];

    /**
     * Check whether the promotion is currently active and within date range.
     */
    public function isCurrentlyActive(): bool
    {
        if (!$this->active) return false;

        $now = now()->startOfDay();

        if ($this->starts_at && $now->lt($this->starts_at)) return false;
        if ($this->ends_at && $now->gt($this->ends_at)) return false;

        if ($this->usage_limit_total !== null && $this->used >= $this->usage_limit_total) return false;

        return true;
    }

    /**
     * Increment used counter (useful when applying promotion).
     */
    public function incrementUsage(int $by = 1)
    {
        $this->used = ($this->used ?? 0) + $by;
        $this->save();
    }

    public function appliesToProduct(?string $product): bool
    {
        if (!$product) {
            return false;
        }

        $products = array_values(array_filter((array) ($this->products ?? [])));
        if (!empty($products)) {
            return in_array($product, $products, true);
        }

        return $this->product === $product;
    }

    public function appliesToUserType(?string $userType): bool
    {
        if (!$userType) {
            return false;
        }

        $targets = array_values(array_filter((array) ($this->targets ?? [])));
        if (!empty($targets)) {
            foreach ($targets as $target) {
                if ($target === 'both' || $target === 'all') {
                    return true;
                }

                if ($target === 'customer') {
                    if (in_array($userType, ['customer', 'user'], true)) {
                        return true;
                    }

                    continue;
                }

                if ($target === 'reseller') {
                    if (in_array($userType, ['reseller', 'agent', 'admin'], true)) {
                        return true;
                    }

                    continue;
                }

                if ($userType === $target) {
                    return true;
                }
            }

            return false;
        }

        if (in_array($this->target, ['customer', 'reseller', 'both', 'all'], true)) {
            return match ($this->target) {
                'customer' => in_array($userType, ['customer', 'user'], true),
                'reseller' => in_array($userType, ['reseller', 'agent', 'admin'], true),
                default => true,
            };
        }

        return false;
    }
}
