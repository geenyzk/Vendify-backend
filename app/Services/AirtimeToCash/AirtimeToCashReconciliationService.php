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
        if (! $provider instanceof LooksUpTransactions) {
            return DB::transaction(function () use ($id) {
                $request = AirtimeToCashRequest::lockForUpdate()->findOrFail($id);
                if ($request->provider_status === 'provider_pending') {
                    ProviderState::move($request, 'manual_review');
                    $request->save();
                }

                return $request;
            });
        }
        try {
            $result = $provider->lookup($request->provider_reference);
        } catch (\Throwable) {
            $result = new ProviderResult('unknown');
        }

        // Not-found/unknown is not proof of non-delivery and never unlocks retry.
        return $this->flow->recordResult($id, $result);
    }
}
