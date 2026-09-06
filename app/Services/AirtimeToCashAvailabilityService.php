<?php

namespace App\Services;

use App\Models\Discount;
use App\Models\Network;

/** The manual conversion policy. IDs are the public contract; names only bridge legacy data. */
class AirtimeToCashAvailabilityService
{
    public static function canonicalName(string $name): string
    {
        $name = strtolower(trim($name));

        return in_array($name, ['t2', 't2 / 9mobile', 't2/9mobile', '9mobile', 'etisalat'], true) ? '9mobile' : $name;
    }

    public function resolve(?int $id, ?string $legacyName = null): ?Network
    {
        if ($id !== null) {
            return Network::find($id);
        }
        $matches = Network::all()->filter(fn ($n) => self::canonicalName($n->name) === self::canonicalName($legacyName ?? ''));

        // Never choose an arbitrary row when legacy names are ambiguous.
        return $matches->count() === 1 ? $matches->first() : null;
    }

    public function rates(Network $network)
    {
        return Discount::where('service_type', 'airtimeToCash')->orderBy('id')->get()
            ->filter(fn ($rate) => $rate->network === null || self::canonicalName($rate->network) === self::canonicalName($network->name));
    }

    public function rate(Network $network): ?Discount
    {
        $rates = $this->rates($network)->filter(fn ($rate) => $rate->isCurrentlyActive());
        $specific = $rates->filter(fn ($rate) => $rate->network !== null);
        $chosen = $specific->isNotEmpty() ? $specific : $rates;

        return $chosen->count() === 1 ? $chosen->first() : null;
    }

    public function inspect(?Network $network, ?float $amount = null): array
    {
        $reason = null;
        $rate = $network ? $this->rate($network) : null;
        if (! $network) {
            $reason = 'Network not found or legacy network name is ambiguous. Select a network by ID.';
        } elseif (! $network->airtime_to_cash_active) {
            $reason = 'Airtime to cash is disabled for this network.';
        } elseif (! preg_match('/^0[789][0-9]{9}$/', trim((string) $network->airtime_to_cash_destination_number))) {
            $reason = 'A valid destination number has not been configured.';
        } elseif ((float) $network->airtime_to_cash_min <= 0 || (float) $network->airtime_to_cash_max < (float) $network->airtime_to_cash_min) {
            $reason = 'The conversion amount limits are invalid.';
        } elseif (! $rate) {
            $reason = 'Conversion rate is currently unavailable. Configure one active Airtime-to-Cash rate.';
        } elseif (! in_array($rate->discount_type, ['percentage', 'fixed'], true) || (float) $rate->value < 0 || ($rate->discount_type === 'percentage' ? (float) $rate->value >= 100 : (float) $rate->value >= (float) $network->airtime_to_cash_min) || $rate->payoutFor((float) $network->airtime_to_cash_min) <= 0) {
            $reason = 'The conversion rate is invalid: payout must be positive throughout the accepted range.';
        } elseif ($amount !== null && (! is_finite($amount) || $amount < (float) $network->airtime_to_cash_min || $amount > (float) $network->airtime_to_cash_max)) {
            $reason = "Amount must be between {$network->airtime_to_cash_min} and {$network->airtime_to_cash_max}.";
        }

        $payout = $reason === null && $amount !== null
            ? $rate->payoutFor($amount)
            : null;

        return [
            'network_id' => $network?->id, 'network' => $network?->name,
            'amount' => $amount, 'available' => $reason === null, 'reason' => $reason,
            'rate_id' => $rate?->id, 'rate_type' => $rate?->discount_type,
            'rate_value' => $rate ? (float) $rate->value : null,
            'payout_amount' => $payout, 'final_amount' => $payout,
            'destination_number' => $network?->airtime_to_cash_destination_number,
        ];
    }
}
