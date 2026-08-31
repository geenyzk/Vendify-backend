<?php

namespace App\Services\Cable;

use App\Models\CablePlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The single source of truth for what a cable subscription costs a customer.
 *
 * Every caller that needs a cable amount — the quote endpoint, the purchase
 * handler, and CablePlan's own `price` accessor — resolves it here, so a
 * quote and the debit that follows it cannot drift apart.
 *
 * Two rules it encodes:
 *
 * 1. The cost comes from the mapping a sale would ACTUALLY be served by.
 *    VTUServiceFactory routes cable by `priority`, filtered to mappings that
 *    are enabled, available and on an active provider. Pricing previously read
 *    an unordered, unfiltered `first()`, so a plan with more than one mapping
 *    (a primary plus a fallback — the configuration the admin form encourages)
 *    could quote one provider's cost while another provider served the sale.
 *    Both now select the same row the same way.
 *
 * 2. The Vendify charge fee is a per-role amount added ON TOP of that cost.
 *    It is never a replacement price: the cable company sets the subscription
 *    price, not the platform. A renewal applies the same fee to the decoder's
 *    own renewal amount rather than to the catalogue price.
 */
class CablePricingService
{
    public const CURRENCY = 'NGN';

    /**
     * The `providerables` row a sale would be served by, mirroring
     * VTUServiceFactory::make()'s cable branch exactly.
     *
     * Falls back to any mapping when none is currently sellable, so an admin
     * editing a plan whose provider is disabled still sees the cost they
     * configured rather than zero.
     */
    public function sellableMapping(CablePlan $plan): ?object
    {
        $query = DB::table('providerables')
            ->join('providers', 'providers.id', '=', 'providerables.provider_id')
            ->where('providerables.providerable_id', $plan->getKey())
            ->where('providerables.providerable_type', CablePlan::class)
            ->where('providers.active', true);

        foreach (['provider_enabled', 'provider_available'] as $flag) {
            if (Schema::hasColumn('providerables', $flag)) {
                $query->where("providerables.{$flag}", true);
            }
        }

        if (Schema::hasColumn('providerables', 'priority')) {
            $query->orderBy('providerables.priority');
        }

        $mapping = $query->first(['providerables.*']);
        if ($mapping) {
            return $mapping;
        }

        // No live mapping. Keep showing the configured cost rather than 0 —
        // the plan is simply not purchasable until an admin fixes routing,
        // which the catalogue query enforces separately.
        return DB::table('providerables')
            ->where('providerable_id', $plan->getKey())
            ->where('providerable_type', CablePlan::class)
            ->first();
    }

    /** The cable company's own subscription cost for this plan. */
    public function baseAmount(CablePlan $plan): float
    {
        return (float) ($this->sellableMapping($plan)->cost_price ?? 0);
    }

    /**
     * The complete customer quote.
     *
     * `$renewalAmount` is the decoder's own renewal figure, read from the
     * server-side verification cache by the caller — never from a client. It
     * is required for a renewal and ignored for a change.
     *
     * @return array{
     *     subscription_type: string,
     *     base_amount: float,
     *     service_fee: float,
     *     total_amount: float,
     *     currency: string
     * }
     */
    public function quote(
        CablePlan $plan,
        string $subscriptionType = 'change',
        ?float $renewalAmount = null,
        ?User $user = null,
    ): array {
        $type = strtolower($subscriptionType) === 'renew' ? 'renew' : 'change';

        $base = $type === 'renew'
            ? (float) $renewalAmount
            : $this->baseAmount($plan);

        // The fee follows whatever base actually applies, so a renewal is not
        // quoted with the catalogue package's fee.
        $fee = round($plan->chargeFeeForBase($base, $user), 2);

        return [
            'subscription_type' => $type,
            'base_amount' => round($base, 2),
            'service_fee' => $fee,
            'total_amount' => round($base + $fee, 2),
            'currency' => self::CURRENCY,
        ];
    }

    /** The amount to charge — the one number the purchase path debits. */
    public function total(
        CablePlan $plan,
        string $subscriptionType = 'change',
        ?float $renewalAmount = null,
        ?User $user = null,
    ): float {
        return $this->quote($plan, $subscriptionType, $renewalAmount, $user)['total_amount'];
    }
}
