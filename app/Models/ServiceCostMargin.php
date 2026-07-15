<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceCostMargin extends Model
{
    use Auditable;
    protected $fillable = [
        'role_id',
        'service_type',
        'cost_price',
        'margin_profit',
        'margin_type',
        'selling_price',
        'is_active',
    ];

    protected $casts = [
        'cost_price' => 'decimal:2',
        'margin_profit' => 'decimal:2',
        'selling_price' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    /**
     * Get the role associated with this cost margin.
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Calculate selling price from cost and margin.
     */
    public function calculateSellingPrice(): float
    {
        return $this->cost_price + $this->margin_profit;
    }

    /**
     * Calculate margin percentage.
     */
    public function getMarginPercentage(): float
    {
        if ($this->cost_price == 0) {
            return 0;
        }

        return ($this->margin_profit / $this->cost_price) * 100;
    }
}
