<?php

namespace App\Console\Commands;

use App\Classes\Vendor\VendorFactory;
use App\Jobs\AutoFundVendor;
use App\Models\Vendor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CheckVendorBalances extends Command
{
    protected $signature = 'vendors:check-balances
                            {--dry-run : Log what would happen without dispatching jobs}';

    protected $description = 'Check all vendor balances and auto-fund those below their threshold';

    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');

        $vendors = Vendor::where('auto_fund_enabled', true)
            ->whereNotNull('auto_fund_threshold')
            ->whereNotNull('auto_fund_amount')
            ->whereNotNull('account_number')
            ->whereNotNull('bank_code')
            ->whereNotNull('funding_provider_id')
            ->where('active', true)
            ->get();

        if ($vendors->isEmpty()) {
            $this->info('No vendors configured for auto-funding.');
            return self::SUCCESS;
        }

        $this->info("Checking {$vendors->count()} vendor(s)...");

        foreach ($vendors as $vendor) {
            $this->checkVendor($vendor, $isDryRun);
        }

        $this->info('Done.');
        return self::SUCCESS;
    }

    private function checkVendor(Vendor $vendor, bool $isDryRun): void
    {
        try {
            // Bust the balance cache to get a real-time reading
            $cacheKey = md5($vendor->base_url . $vendor->username . $vendor->password . '_balance');
            Cache::forget($cacheKey);

            $vendorInstance = VendorFactory::make($vendor);
            $rawBalance = $vendorInstance->checkBalance();
            $balance = (float) str_replace(',', '', $rawBalance);

            $threshold = (float) $vendor->auto_fund_threshold;

            $this->line(sprintf(
                '  [%s] balance=%.2f  threshold=%.2f  fund_amount=%.2f',
                $vendor->name,
                $balance,
                $threshold,
                (float) $vendor->auto_fund_amount
            ));

            if ($balance >= $threshold) {
                Log::info("CheckVendorBalances: [{$vendor->name}] balance OK ({$balance} >= {$threshold}).");
                return;
            }

            // Prevent duplicate jobs within the same check window using a lock
            $lockKey = "auto_fund_lock_{$vendor->id}";
            if (Cache::has($lockKey)) {
                $this->warn("  [{$vendor->name}] auto-fund already queued — skipping.");
                return;
            }

            if ($isDryRun) {
                $this->warn("  [{$vendor->name}] DRY-RUN: would dispatch AutoFundVendor (balance {$balance} < threshold {$threshold}).");
                return;
            }

            // Set a 30-minute lock so we don't double-dispatch on the next scheduler tick
            Cache::put($lockKey, true, now()->addMinutes(30));

            AutoFundVendor::dispatch($vendor, $balance);

            $this->info("  [{$vendor->name}] AutoFundVendor dispatched.");
            Log::info("CheckVendorBalances: dispatched AutoFundVendor for [{$vendor->name}]", [
                'balance'   => $balance,
                'threshold' => $threshold,
                'amount'    => $vendor->auto_fund_amount,
            ]);

        } catch (\Throwable $e) {
            $this->error("  [{$vendor->name}] error: {$e->getMessage()}");
            Log::error("CheckVendorBalances: failed for [{$vendor->name}]", ['error' => $e->getMessage()]);
        }
    }
}
