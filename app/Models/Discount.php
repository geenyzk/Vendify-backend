<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class Discount extends Model
{
    protected $hidden = ["created_at", "updated_at"];

    protected $fillable = ["id", "name", "category", "type", "min", "max", "active"];

    protected $casts = [
        "active" => 'boolean',
    ];

    /**
     * Per-role discount percentages for this network/discount record.
     */
    public function roleDiscounts(): HasMany
    {
        return $this->hasMany(DiscountRole::class, 'discount_id');
    }

    public function toArray()
    {
        $array = parent::toArray();
        $array['network'] = $array['name'];

        return $array;
    }

    function scopeAirtime()
    {
        return $this->where("type", "airtime")
        ->get()->map(function ($airtime) {
            $airtime->network = $airtime->name;
            return $airtime;
        });
    }

    public function getPriceAttribute()
    {
        $user = Auth::user();
        if (!$user?->role_id) {
            return 0;
        }

        return $this->roleDiscounts()->where('role_id', $user->role_id)->value('discount') ?? 0;
    }

    function scopeGetElectricity($query, $name)
    {
        Log::info(["name: " => $name]);
        return $query->where("name", $name)
        ->first();
    }

    function scopeGetAllElectricity()
    {
        return $this->where("type", "electricity")
        ->get();
    }

    public static function getAmountRangeError(float $amount, string $category): ?string
    {
        $discount = self::where("category", $category)->first();

        if (!$discount) {
            return "Invalid category.";
        }

        if ($amount < $discount->min || $amount > $discount->max) {
            return "Amount must be between {$discount->min} and {$discount->max}.";
        }

        return null; // no error
    }

    /**
     * Calculate the discounted amount based on the original amount.
     * Returns the final amount AFTER discount is applied.
     */
    static public function getDiscountedAmount(float $amount, string $name): float
    {
        $discount = self::where("name", $name)
            ->orWhere("category", $name)
            ->first();

        // If no discount found, return original amount
        if (!$discount) {
            return $amount;
        }

        $user = Auth::user();
        $percent = 0;
        if ($user?->role_id) {
            $percent = $discount->roleDiscounts()->where('role_id', $user->role_id)->value('discount') ?? 0;
        }

        // Calculate final amount after discount
        $finalAmount = $amount - (($percent / 100) * $amount);

        return round($finalAmount, 2);
    }
}
