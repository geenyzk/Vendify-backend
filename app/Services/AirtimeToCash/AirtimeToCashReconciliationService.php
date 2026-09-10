<?php

namespace App\Services\AirtimeToCash;

use App\Models\AirtimeToCashRequest;
use Illuminate\Support\Facades\DB;

/** Explicitly invoked; there is no scheduled polling or transfer retry. */
final class AirtimeToCashReconciliationService
{
    public function __construct(private AirtimeToCashProviderManager $manager, private AirtimeToCashProviderService $flow) {}

    public function reconcile(int $id): AirtimeToCashRequest
    {
        $request = DB::transaction(function () use ($id) {
            $request = AirtimeToCashRequest::where('processing_mode', 'provider')->lockForUpdate()->findOrFail($id);
            if (in_array($request->provider_status, ['provider_confirmed', 'settlement_pending', 'completed'], true)) {
                return $request;
            }
            if (! in_array($request->provider_status, ['processing', 'provider_pending', 'manual_review'], true)) {
                throw new \DomainException('This conversion is not awaiting reconciliation.');
            }
            if ((int) $request->provider_attempt_count === 0) {
                throw new \DomainException('No transfer attempt is recorded. This is a setup/verification issue, not a transaction to reconcile.');
            }
            if (! $this->manager->bound($request) instanceof LooksUpTransactions) {
                throw new \DomainException('Automation has no documented transaction lookup API. Check the provider dashboard/support and safe call logs; automatic reconciliation is unavailable. Do not retry an uncertain transfer.');
            }
            if ($request->last_provider_check_at?->gt(now()->subMinute())) {
                throw new \DomainException('Wait before checking this conversion again.');
            }
            $request->last_provider_check_at = now();
            if ($request->provider_status === 'processing') {
                ProviderState::move($request, 'provider_pending');
            }
            $request->save();

            return $request;
        });
        if (in_array($request->provider_status, ['provider_confirmed', 'settlement_pending', 'completed'], true)) {
            return $this->flow->settleConfirmed($request);
        }
        $provider = $this->manager->bound($request);
        try {
            $result = $provider->lookup($request->provider_reference);
        } catch (\Throwable) {
            $result = new ProviderResult('unknown');
        }

        // Not-found/unknown is not proof of non-delivery and never unlocks retry.
        return $this->flow->recordResult($id, $result);
    }
}
