<?php

namespace App\Console\Commands;

use App\Classes\Vendor\VendorFactory;
use App\Models\Vendor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncVendorPlans extends Command
{
    protected $signature = 'vendors:sync-plans';

    protected $description = 'Pull each vendor\'s live plan catalogue and upsert it into local DataPlan rows, for vendors whose class supports it';

    public function handle(): int
    {
        $vendors = Vendor::where('active', true)->get();

        if ($vendors->isEmpty()) {
            $this->info('No active vendors configured.');
            return self::SUCCESS;
        }

        foreach ($vendors as $vendor) {
            try {
                $vendorInstance = VendorFactory::make($vendor);
            } catch (\Throwable $e) {
                continue;
            }

            if (!method_exists($vendorInstance, 'syncPlans')) {
                continue;
            }

            try {
                $summary = $vendorInstance->syncPlans();
                $this->info(sprintf(
                    '  [%s] created=%d updated=%d skipped=%d',
                    $vendor->name,
                    $summary['created'] ?? 0,
                    $summary['updated'] ?? 0,
                    $summary['skipped'] ?? 0,
                ));
            } catch (\Throwable $e) {
                $this->error("  [{$vendor->name}] sync failed: {$e->getMessage()}");
                Log::error("SyncVendorPlans: failed for [{$vendor->name}]", ['error' => $e->getMessage()]);
            }
        }

        $this->info('Done.');
        return self::SUCCESS;
    }
}
