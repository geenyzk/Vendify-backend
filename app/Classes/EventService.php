<?php

namespace App\Classes;

use App\Models\Event;
use App\Models\EventAward;
use App\Models\Transaction;
use App\Models\User;

class EventService
{
    // Funding transactions are excluded from purchase-volume/count metrics —
    // they measure spending, not adding money to the wallet. Matches the
    // same list AnalyticsController::FUNDING_TYPES already uses.
    protected const FUNDING_TYPES = ['wallet_funding', 'manual_funding'];

    /**
     * Check every active Event against this user's current metrics and
     * award anything newly earned. Safe to call repeatedly — awarding is
     * idempotent per threshold crossed (tracked via EventAward::times_earned).
     */
    public static function checkAndAward(User $user): void
    {
        Event::where('active', true)->get()->each(
            fn (Event $event) => static::evaluate($event, $user),
        );
    }

    protected static function evaluate(Event $event, User $user): void
    {
        $value = static::metricValue($event, $user);
        if ($value <= 0) {
            return;
        }

        $award = EventAward::firstOrCreate(
            ['event_id' => $event->id, 'user_id' => $user->id],
            ['times_earned' => 0],
        );

        $targetTimes = $event->repeatable
            ? (int) floor($value / (float) $event->threshold)
            : ($value >= (float) $event->threshold ? 1 : 0);

        $newlyEarned = $targetTimes - $award->times_earned;
        if ($newlyEarned <= 0) {
            return;
        }

        if (in_array($event->reward_type, ['cash', 'both'], true) && $event->cash_amount > 0) {
            $user->increment('wallet_balance', (float) $event->cash_amount * $newlyEarned);
        }
        // Badge reward is cosmetic-only — times_earned > 0 on this award row
        // *is* the record of having earned the badge; nothing else to credit.

        $award->times_earned += $newlyEarned;
        $award->last_earned_at = now();
        $award->save();
    }

    protected static function metricValue(Event $event, User $user): float
    {
        return match ($event->metric) {
            'referral_count' => (float) User::where('referred_by', $user->id)->count(),

            'transaction_volume' => (float) Transaction::where('user_id', $user->id)
                ->where('status', 'success')
                ->whereNotIn('transaction_type', self::FUNDING_TYPES)
                ->when($event->service_type, fn ($q) => $q->where('transaction_type', $event->service_type))
                ->sum('amount'),

            'transaction_count' => (float) Transaction::where('user_id', $user->id)
                ->where('status', 'success')
                ->whereNotIn('transaction_type', self::FUNDING_TYPES)
                ->when($event->service_type, fn ($q) => $q->where('transaction_type', $event->service_type))
                ->count(),

            'wallet_funding_total' => (float) Transaction::where('user_id', $user->id)
                ->where('status', 'success')
                ->whereIn('transaction_type', self::FUNDING_TYPES)
                ->sum('amount'),

            default => 0.0,
        };
    }
}
