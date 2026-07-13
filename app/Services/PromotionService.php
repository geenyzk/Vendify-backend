<?php

namespace App\Services;

use App\Models\Promotion;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class PromotionService
{
    /**
     * Apply a promotion code and calculate the discount.
     *
     * @param string $code The promotion code
     * @param float $amount The transaction amount
     * @param string $product The product type (airtime, data, bundle)
     * @param string|null $provider The network provider (MTN, AIRTEL, GLO, 9MOBILE)
     * @param User|null $user The user applying the promotion
     * @return array ['success' => bool, 'promotion_id' => ?int, 'discount_amount' => float, 'final_amount' => float, 'message' => string]
     */
    public static function applyPromotion(
        string $code,
        float $amount,
        string $product,
        ?string $provider = null,
        ?User $user = null
    ): array {
        try {
            if (empty($code)) {
                return [
                    'success' => true,
                    'promotion_id' => null,
                    'discount_amount' => 0,
                    'final_amount' => $amount,
                    'message' => 'No promotion code provided',
                ];
            }

            $promotion = Promotion::where('code', strtoupper($code))
                ->where('active', true)
                ->first();

            if (!$promotion) {
                return [
                    'success' => false,
                    'promotion_id' => null,
                    'discount_amount' => 0,
                    'final_amount' => $amount,
                    'message' => 'Invalid or expired promo code',
                ];
            }

            // Check if promotion is currently active (date range + usage limits)
            if (!$promotion->isCurrentlyActive()) {
                return [
                    'success' => false,
                    'promotion_id' => null,
                    'discount_amount' => 0,
                    'final_amount' => $amount,
                    'message' => 'This promo code is no longer valid',
                ];
            }

            // Check if user is eligible (if user provided)
            if ($user && !self::isUserEligible($promotion, $user)) {
                return [
                    'success' => false,
                    'promotion_id' => null,
                    'discount_amount' => 0,
                    'final_amount' => $amount,
                    'message' => 'You are not eligible for this promo code',
                ];
            }

            // Check if product matches
            if (!$promotion->appliesToProduct($product)) {
                return [
                    'success' => false,
                    'promotion_id' => null,
                    'discount_amount' => 0,
                    'final_amount' => $amount,
                    'message' => "Promo code is not applicable for {$product}",
                ];
            }

            // Check if provider matches (if provider is specified in promotion)
            if ($provider && $promotion->provider && $promotion->provider !== 'All' && $promotion->provider !== strtoupper($provider)) {
                return [
                    'success' => false,
                    'promotion_id' => null,
                    'discount_amount' => 0,
                    'final_amount' => $amount,
                    'message' => "Promo code is not applicable for {$provider}",
                ];
            }

            // Validate conditions
            if (!self::validateConditions($promotion, ['amount' => $amount, 'provider' => $provider])) {
                return [
                    'success' => false,
                    'promotion_id' => null,
                    'discount_amount' => 0,
                    'final_amount' => $amount,
                    'message' => 'Transaction does not meet promo code requirements',
                ];
            }

            // Calculate discount
            $discountAmount = self::calculateDiscount($promotion, $amount);
            $finalAmount = max(0, $amount - $discountAmount);

            // Increment usage
            $promotion->incrementUsage();

            return [
                'success' => true,
                'promotion_id' => $promotion->id,
                'discount_amount' => $discountAmount,
                'final_amount' => $finalAmount,
                'message' => 'Promo code applied successfully',
            ];
        } catch (\Exception $e) {
            Log::error('Error applying promotion: ' . $e->getMessage());
            return [
                'success' => false,
                'promotion_id' => null,
                'discount_amount' => 0,
                'final_amount' => $amount,
                'message' => 'Error applying promo code',
            ];
        }
    }

    /**
     * Calculate the discount amount based on promotion type and value.
     */
    private static function calculateDiscount(Promotion $promotion, float $amount): float
    {
        $type = $promotion->type;
        $value = $promotion->value;

        if ($type === 'percentage') {
            return round(($value / 100) * $amount, 2);
        } elseif ($type === 'fixed') {
            return min($value, $amount); // Can't discount more than amount
        } elseif ($type === 'bonus_data') {
            return 0; // Bonus data doesn't reduce payment amount
        } elseif ($type === 'cashback') {
            return 0; // Cashback is applied after transaction
        }

        return 0;
    }

    /**
     * Check if user is eligible for the promotion
     */
    private static function isUserEligible(Promotion $promotion, User $user): bool
    {
        $target = $promotion->target;

        // No target / "all" / legacy "both" → everyone is eligible.
        if (!$target || $target === 'both' || $target === 'all') {
            return true;
        }

        // Legacy coarse audiences (kept for pre-existing promos).
        if ($target === 'customer') {
            return $user->user_type === 'customer' || $user->user_type === 'user';
        }
        if ($target === 'reseller') {
            return in_array($user->user_type, ['reseller', 'agent', 'admin']);
        }

        // Otherwise the target names a specific Role — match by slug or name.
        $role = $user->role;
        if (!$role) {
            return false;
        }

        return in_array($target, array_filter([$role->slug ?? null, $role->name ?? null]), true);
    }

    /**
     * Validate promotion conditions
     */
    private static function validateConditions(Promotion $promotion, array $data): bool
    {
        if (!$promotion->conditions || empty($promotion->conditions)) {
            return true; // No conditions = always valid
        }

        foreach ($promotion->conditions as $condition) {
            if (!self::validateCondition($condition, $data)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate a single condition
     */
    private static function validateCondition(array $condition, array $data): bool
    {
        $kind = $condition['kind'] ?? null;

        if ($kind === 'min_amount') {
            $minAmount = $condition['amount'] ?? 0;
            return ($data['amount'] ?? 0) >= $minAmount;
        }

        if ($kind === 'provider') {
            $requiredProvider = strtoupper($condition['provider'] ?? '');
            $userProvider = strtoupper($data['provider'] ?? '');
            return $userProvider === $requiredProvider;
        }

        if ($kind === 'first_time') {
            // TODO: Implement first-time buyer check
            return true;
        }

        if ($kind === 'recurring_topup') {
            // TODO: Implement recurring top-up check
            return true;
        }

        return true; // Unknown condition = pass
    }
}
