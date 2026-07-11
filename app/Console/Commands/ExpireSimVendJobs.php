<?php

namespace App\Console\Commands;

use App\Classes\AdminNotifier;
use App\Classes\Vendor\Providers\SimVending;
use App\Models\SimVendJob;
use App\Models\Vendor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Safety net for SIM vend jobs nobody finished:
 *
 *  - claimed jobs whose lease (plus grace) lapsed with no ack — the device
 *    died or lost connectivity mid-vend. The customer is refunded and the
 *    admin alerted to reconcile, but the job is NEVER re-dispatched: the
 *    USSD may have executed before the device went dark, and a retry would
 *    risk delivering twice.
 *  - pending jobs nothing claimed within the TTL — the device that looked
 *    online at purchase time evidently went away. Straight refund.
 *
 * Refunds go through SimVending->settleCallback(), the same locked
 * pending→fail path vendor webhooks use, so a racing late ack can never
 * double-move money.
 */
class ExpireSimVendJobs extends Command
{
    protected $signature = 'sim:expire-jobs';

    protected $description = 'Fail and refund SIM vend jobs whose lease or pending TTL has expired';

    public function handle(): int
    {
        $vendor = Vendor::where('sub_category', 'simvend')->first();

        $expiredLeases = SimVendJob::where('status', SimVendJob::STATUS_CLAIMED)
            ->where('lease_expires_at', '<', now()->subSeconds((int) config('simvending.lease_grace')))
            ->pluck('id');

        foreach ($expiredLeases as $id) {
            $this->expire($id, $vendor, 'lease_expired', notify: true);
        }

        $staleUnclaimed = SimVendJob::where('status', SimVendJob::STATUS_PENDING)
            ->where('created_at', '<', now()->subSeconds((int) config('simvending.pending_ttl')))
            ->pluck('id');

        foreach ($staleUnclaimed as $id) {
            $this->expire($id, $vendor, 'unclaimed_ttl_expired', notify: false);
        }

        $total = $expiredLeases->count() + $staleUnclaimed->count();
        $this->info("Expired {$total} SIM vend job(s).");

        return self::SUCCESS;
    }

    private function expire(int $jobId, ?Vendor $vendor, string $reason, bool $notify): void
    {
        if (!$vendor) {
            // Without the vendor row we can't settle (refund) — leave the job
            // untouched rather than failing it while the customer's money
            // stays held on a pending transaction.
            Log::error('sim:expire-jobs: no simvend vendor row — cannot expire jobs safely', [
                'job_id' => $jobId,
            ]);
            return;
        }

        // Status flip and refund commit or roll back together — a settle
        // failure must not leave a failed job with the customer's funds
        // still held (settleCallback's own DB::transaction joins this one).
        $job = DB::transaction(function () use ($jobId, $vendor, $reason) {
            $job = SimVendJob::whereKey($jobId)->lockForUpdate()->first();

            // Re-check under the lock — an ack may have landed between the
            // sweep query and this lock, in which case the job is already
            // settled and must be left alone.
            if (!$job || $job->isTerminal()) {
                return null;
            }
            if ($reason === 'lease_expired' && $job->status !== SimVendJob::STATUS_CLAIMED) {
                return null;
            }
            if ($reason === 'unclaimed_ttl_expired' && $job->status !== SimVendJob::STATUS_PENDING) {
                return null;
            }

            $job->update([
                'status' => SimVendJob::STATUS_FAILED,
                'failure_reason' => $reason,
            ]);

            (new SimVending($vendor))->settleCallback([
                'status' => 'fail',
                'tx_ref' => $job->transaction_reference,
                'response_message' => $reason === 'lease_expired'
                    ? 'SIM device did not confirm delivery in time'
                    : 'No SIM device picked up the vend in time',
            ]);

            return $job;
        });

        if (!$job) {
            return;
        }

        if ($notify) {
            AdminNotifier::notifySimJobExpired($job);
        }
    }
}
