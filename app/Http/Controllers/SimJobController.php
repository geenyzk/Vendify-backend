<?php

namespace App\Http\Controllers;

use App\Classes\AdminNotifier;
use App\Classes\Vendor\Providers\SimVending;
use App\Models\Sim;
use App\Models\SimDevice;
use App\Models\SimVendJob;
use App\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Claim/ack half of the SIM-device channel. A device atomically claims the
 * oldest pending job one of its SIMs can fulfil (leased — one device only),
 * executes the USSD vend, then acks the outcome. The ack settles the
 * customer's pending transaction through SimVending->settleCallback(), the
 * same locking/refund/reward path vendor webhooks use.
 */
class SimJobController extends Controller
{
    public function claim(Request $request, string $slug): JsonResponse
    {
        /** @var SimDevice $device */
        $device = $request->attributes->get('simDevice');

        $sims = $device->sims()->where('enabled', true)->get();
        if ($sims->isEmpty()) {
            return $this->success(['jobs' => []]);
        }

        $job = DB::transaction(function () use ($device, $sims) {
            // Oldest-first over this device's networks; row locks stop two
            // devices (or two polls) claiming the same job. Scanning a small
            // batch lets a job whose amount exceeds this device's SIM stock
            // be skipped without blocking the rest of the queue.
            $candidates = SimVendJob::where('status', SimVendJob::STATUS_PENDING)
                ->whereIn('network', $sims->pluck('network')->unique()->all())
                ->orderBy('id')
                ->lockForUpdate()
                ->limit(20)
                ->get();

            foreach ($candidates as $candidate) {
                $sim = $this->pickSim($sims, $candidate);
                if (!$sim) {
                    continue;
                }

                $candidate->update([
                    'status' => SimVendJob::STATUS_CLAIMED,
                    'sim_device_id' => $device->id,
                    'sim_id' => $sim->id,
                    'attempts' => $candidate->attempts + 1,
                    'claimed_at' => now(),
                    'lease_expires_at' => now()->addSeconds((int) config('simvending.lease_seconds')),
                ]);

                return $candidate;
            }

            return null;
        });

        if (!$job) {
            return $this->success(['jobs' => []]);
        }

        return $this->success(['jobs' => [[
            'id' => $job->id,
            'reference' => $job->transaction_reference,
            'service' => $job->service,
            'network' => $job->network,
            'phone' => $job->phone,
            'amount' => (float) $job->amount,
            'plan' => $job->plan_snapshot,
            'sim_slot' => $job->sim?->slot_index,
            'lease_expires_at' => $job->lease_expires_at?->toIso8601String(),
        ]]]);
    }

    /**
     * NOTE: both route params ({slug} then {id}) MUST be declared here in
     * route order — Laravel binds them positionally, and omitting $slug
     * hands the slug in as $id (the exact bug that silently broke child
     * directive acks; see ChildDirectiveController::ack).
     */
    public function ack(Request $request, string $slug, string $id): JsonResponse
    {
        /** @var SimDevice $device */
        $device = $request->attributes->get('simDevice');

        $validated = $request->validate([
            'result' => ['required', 'in:executed,failed'],
            'note' => ['nullable', 'string', 'max:1000'],
            'retryable' => ['nullable', 'boolean'],
            'receipt' => ['nullable', 'array'],
            'sim' => ['nullable', 'array'],
            'sim.airtime_balance' => ['nullable', 'numeric', 'min:0'],
            'sim.data_balance_mb' => ['nullable', 'numeric', 'min:0'],
        ]);

        [$status, $body] = DB::transaction(function () use ($device, $id, $validated) {
            $job = SimVendJob::whereKey($id)->lockForUpdate()->first();

            if (!$job || (int) $job->sim_device_id !== (int) $device->id) {
                return [404, ['message' => 'Job not found for this device']];
            }

            if ($job->status !== SimVendJob::STATUS_CLAIMED) {
                // Terminal already — most likely the expiry sweeper refunded
                // it before this late ack arrived. If the device now says it
                // actually delivered, money has moved wrongly: flag it for a
                // manual reconcile, but never move money on a settled row.
                if ($validated['result'] === 'executed' && $job->status === SimVendJob::STATUS_FAILED) {
                    AdminNotifier::notifySimVendDiscrepancy($job);
                }
                return [409, ['message' => "Job already {$job->status}"]];
            }

            $this->applySimReport($job, $validated);

            if ($validated['result'] === 'executed') {
                $job->update([
                    'status' => SimVendJob::STATUS_SUCCESS,
                    'acked_at' => now(),
                    'result' => $validated['receipt'] ?? null,
                    'failure_reason' => null,
                ]);
                $this->settle($job, 'success', $validated['note'] ?? 'Delivered via SIM');

                return [200, ['message' => 'Job settled as success']];
            }

            $retryable = (bool) ($validated['retryable'] ?? false);
            if ($retryable && $job->attempts < $job->max_attempts) {
                // Confirmed non-delivery with attempts left: back to the
                // queue for another SIM/device. Funds stay reserved; the
                // transaction stays pending.
                $job->update([
                    'status' => SimVendJob::STATUS_PENDING,
                    'sim_device_id' => null,
                    'sim_id' => null,
                    'claimed_at' => null,
                    'lease_expires_at' => null,
                    'failure_reason' => $validated['note'] ?? 'Retryable device failure',
                ]);

                return [200, ['message' => 'Job requeued for retry']];
            }

            $job->update([
                'status' => SimVendJob::STATUS_FAILED,
                'acked_at' => now(),
                'result' => $validated['receipt'] ?? null,
                'failure_reason' => $validated['note'] ?? 'Device reported failure',
            ]);
            $this->settle($job, 'fail', $validated['note'] ?? 'SIM vend failed');

            return [200, ['message' => 'Job settled as failed, transaction refunded']];
        });

        return response()->json($body, $status);
    }

    /** Device's SIM best placed to fulfil this job, or null if none can. */
    private function pickSim($sims, SimVendJob $job): ?Sim
    {
        $need = $job->service === 'data'
            ? (float) ($job->plan_snapshot['size_mb'] ?? 0)
            : (float) $job->amount + (float) config('simvending.airtime_reserve');
        $balanceColumn = $job->service === 'data' ? 'data_balance_mb' : 'airtime_balance';
        $supportColumn = $job->service === 'data' ? 'supports_data' : 'supports_airtime';

        return $sims
            ->filter(fn (Sim $sim) => strtolower($sim->network) === strtolower($job->network)
                && $sim->{$supportColumn}
                && (float) $sim->{$balanceColumn} >= $need)
            ->sortByDesc($balanceColumn)
            ->first();
    }

    /**
     * Fold the ack's post-vend SIM state in: trust the device's reported
     * balance when given, otherwise decrement our last-known figure by what
     * this vend consumed so eligibility stays roughly honest between
     * heartbeats.
     */
    private function applySimReport(SimVendJob $job, array $validated): void
    {
        $sim = $job->sim_id ? Sim::find($job->sim_id) : null;
        if (!$sim) {
            return;
        }

        $reported = $validated['sim'] ?? [];
        $updates = ['balance_reported_at' => now()];

        if (isset($reported['airtime_balance'])) {
            $updates['airtime_balance'] = $reported['airtime_balance'];
        } elseif ($validated['result'] === 'executed' && $job->service === 'airtime') {
            $updates['airtime_balance'] = max(0, $sim->airtime_balance - (float) $job->amount);
        }

        if (isset($reported['data_balance_mb'])) {
            $updates['data_balance_mb'] = $reported['data_balance_mb'];
        } elseif ($validated['result'] === 'executed' && $job->service === 'data') {
            $updates['data_balance_mb'] = max(0, $sim->data_balance_mb - (float) ($job->plan_snapshot['size_mb'] ?? 0));
        }

        $sim->update($updates);
    }

    /**
     * Settle the customer's pending transaction with the exact semantics a
     * vendor webhook gets: pending→success pays rewards, pending→fail
     * refunds, anything already terminal is a no-op (settleCallback locks
     * and re-checks the transaction row itself).
     */
    private function settle(SimVendJob $job, string $status, string $message): void
    {
        $vendor = Vendor::where('sub_category', 'simvend')->first();
        if (!$vendor) {
            Log::error('SimJobController: no simvend vendor row — cannot settle transaction', [
                'job_id' => $job->id,
                'tx_ref' => $job->transaction_reference,
            ]);
            return;
        }

        (new SimVending($vendor))->settleCallback([
            'status' => $status,
            'tx_ref' => $job->transaction_reference,
            'response_message' => $message,
        ]);
    }
}
