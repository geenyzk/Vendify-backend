<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Promotion extends Model
{
    use HasFactory;

    protected $table = 'promotions';

    protected $fillable = [
        'name',
        'code',
        'apply',
        'target',
        'product',
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
}
