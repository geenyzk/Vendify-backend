<?php

namespace App\Services\AiManager\Tools;

use App\Models\User;
use App\Models\Vendor;

/**
 * Current balances for the upstream service vendors, with each vendor's
 * auto-fund threshold and a flag for whether it has dropped below it. Lets the
 * assistant answer "are we about to run out of float anywhere?". Balances are
 * read through the Vendor model's cached balance accessor (up to 60 min old),
 * so this never hammers vendor APIs.
 */
class GetVendorBalancesTool extends AiTool
{
    public function name(): string
    {
        return 'get_vendor_balances';
    }

    public function description(): string
    {
        return 'List the upstream service vendors with their current (cached, up to 60 minutes old) wallet balance, whether they are active, their auto-fund threshold, and whether the balance is below that threshold. Use this to check whether the platform is at risk of running out of float with any provider.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false];
    }

    public function handle(array $arguments, User $actor): array
    {
        $vendors = Vendor::query()->orderBy('name')->get();

        $rows = $vendors->map(function (Vendor $vendor) {
            $rawBalance = $vendor->balance; // cached accessor; null on lookup failure
            $balance = $rawBalance === null ? null : (float) str_replace(',', '', (string) $rawBalance);
            $threshold = $vendor->auto_fund_threshold;

            return [
                'id' => $vendor->id,
                'name' => $vendor->name,
                'code' => $vendor->code,
                'active' => (bool) $vendor->active,
                'balance' => $balance,
                'balance_available' => $rawBalance !== null,
                'auto_fund_enabled' => (bool) $vendor->auto_fund_enabled,
                'auto_fund_threshold' => $threshold,
                'below_threshold' => ($balance !== null && $threshold !== null)
                    ? $balance < (float) $threshold
                    : null,
            ];
        });

        return [
            'currency' => 'NGN',
            'note' => 'Balances are cached and may be up to 60 minutes old.',
            'vendors' => $rows,
        ];
    }
}
