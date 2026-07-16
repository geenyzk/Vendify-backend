<?php

namespace App\Http\Controllers;

use App\Classes\Payment\Payment;
use App\Models\ChildCreditEvent;
use App\Models\ChildInstance;
use App\Models\ChildVirtualAccount;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Parent-managed funding for an affiliate child's customers, over the same
 * HMAC-verified child channel as directives. The parent aggregates all funding:
 *
 *   1. The child asks the parent to create a virtual account for a customer
 *      (POST /virtual-accounts). Money paid into it lands in the parent's
 *      payment provider.
 *   2. On funding, the parent records a credit event (see
 *      PaymentBase::relayChildFunding).
 *   3. The child pulls credit events (GET /credit-events), credits its local
 *      customer, and acks — the reliable pull/ack pattern used for directives,
 *      so a briefly-offline child never loses a credit.
 *
 * Every endpoint requires the affiliate's "Aggregate funding to this platform"
 * toggle to be on; otherwise the parent isn't managing this child's funding.
 */
class ChildFundingController extends Controller
{
    /** Generate (or return the existing) virtual account for a child customer. */
    public function requestAccount(Request $request): JsonResponse
    {
        /** @var ChildInstance $instance */
        $instance = $request->attributes->get('childInstance');

        if (!$instance->fundingAggregationEnabled()) {
            return $this->fail([], 'Funding aggregation is not enabled for this affiliate.', 403);
        }

        $data = $request->validate([
            'external_customer_id' => 'required|string|max:191',
            'email' => 'required|email|max:191',
            'name' => 'required|string|max:191',
            'phone' => 'nullable|string|max:32',
            // The child sends regenerate=true on login/register so the customer
            // always gets a fresh, parent-owned account. Optional reason is
            // recorded on the audit entry ("login" | "register" | ...).
            'regenerate' => 'nullable|boolean',
            'reason' => 'nullable|string|max:40',
        ]);

        $existing = ChildVirtualAccount::where('child_instance_id', $instance->id)
            ->where('external_customer_id', $data['external_customer_id'])
            ->first();

        if ($existing && !($data['regenerate'] ?? false)) {
            // Idempotent: one account per (child, customer). Return the stored
            // one instead of burning another reserved account at the provider.
            return $this->success($this->accountPayload($existing), 'Existing account returned');
        }

        // Regenerate: drop the current account(s) for this customer so the new
        // one replaces them. NOTE: a payment into a replaced account will no
        // longer auto-map — the child should surface only the latest account.
        if ($existing) {
            ChildVirtualAccount::where('child_instance_id', $instance->id)
                ->where('external_customer_id', $data['external_customer_id'])
                ->delete();
        }

        $account = Payment::generateChildAccount([
            'email' => $data['email'],
            'name' => $data['name'],
            'phone' => $data['phone'] ?? null,
        ]);

        if (!$account) {
            Log::error('Child virtual account generation returned nothing', [
                'child_instance_id' => $instance->id,
                'external_customer_id' => $data['external_customer_id'],
            ]);

            return $this->fail([], 'Could not create a funding account right now. Please try again.', 502);
        }

        // Link to the synced ChildCustomer row when one already exists.
        $childCustomerId = $instance->customers()
            ->where('external_id', $data['external_customer_id'])
            ->value('id');

        $record = ChildVirtualAccount::create([
            'child_instance_id' => $instance->id,
            'external_customer_id' => $data['external_customer_id'],
            'child_customer_id' => $childCustomerId,
            'provider' => $account['provider'],
            'account_number' => $account['account_number'],
            'bank_name' => $account['bank_name'] ?? null,
            'account_name' => $account['account_name'] ?? null,
            'reference' => $account['reference'] ?? null,
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
        ]);

        $regenerated = (bool) ($existing);
        AuditLogger::record(
            $regenerated ? 'child_virtual_account_regenerated' : 'child_virtual_account_issued',
            subject: $record,
            description: sprintf(
                '%s virtual account %s for %s customer %s%s',
                $regenerated ? 'Regenerated' : 'Issued',
                $record->account_number,
                $instance->name,
                $data['external_customer_id'],
                !empty($data['reason']) ? " (on {$data['reason']})" : '',
            ),
            context: [
                'child_instance_id' => $instance->id,
                'external_customer_id' => $data['external_customer_id'],
                'provider' => $account['provider'],
                'reason' => $data['reason'] ?? null,
            ],
            subjectLabel: $record->account_number,
        );

        return $this->success($this->accountPayload($record), 'Account created', 201);
    }

    /** Pending credit events for this child; marks them delivered. */
    public function pullCreditEvents(Request $request): JsonResponse
    {
        /** @var ChildInstance $instance */
        $instance = $request->attributes->get('childInstance');

        $events = ChildCreditEvent::where('child_instance_id', $instance->id)
            ->whereIn('status', [ChildCreditEvent::STATUS_PENDING, ChildCreditEvent::STATUS_DELIVERED])
            ->orderBy('id')
            ->limit(50)
            ->get();

        // Mark as delivered on read; the child confirms application via ack.
        // Re-delivering a still-unacked event is fine — the child applies each
        // by its unique reference, so a re-fetch never double-credits.
        ChildCreditEvent::whereIn('id', $events->pluck('id'))
            ->where('status', ChildCreditEvent::STATUS_PENDING)
            ->update(['status' => ChildCreditEvent::STATUS_DELIVERED, 'delivered_at' => now()]);

        return $this->success($events->map(fn (ChildCreditEvent $e) => [
            'id' => $e->id,
            'external_customer_id' => $e->external_customer_id,
            'amount' => (float) $e->amount,
            'gross_amount' => (float) $e->gross_amount,
            'fee' => (float) $e->fee,
            'provider' => $e->provider,
            'reference' => $e->reference,
            'created_at' => $e->created_at?->toDateTimeString(),
        ])->all());
    }

    /**
     * Child confirms it applied (or couldn't apply) a credit event.
     * Route params arrive positionally — (Request, slug, id) — the same gotcha
     * ChildDirectiveController::ack documents.
     */
    public function ackCreditEvent(Request $request, string $slug, string $id): JsonResponse
    {
        /** @var ChildInstance $instance */
        $instance = $request->attributes->get('childInstance');

        $event = ChildCreditEvent::where('child_instance_id', $instance->id)->find((int) $id);
        if (!$event) {
            return $this->fail([], 'Credit event not found', 404);
        }

        // HMAC already pinned these exact bytes — read them directly.
        $body = json_decode($request->getContent(), true);
        $result = is_array($body) && in_array($body['result'] ?? null, ['credited', 'failed'], true)
            ? $body['result']
            : ChildCreditEvent::STATUS_CREDITED;
        $note = is_array($body) && isset($body['note'])
            ? mb_substr((string) $body['note'], 0, 500)
            : null;

        $event->update([
            'status' => $result,
            'acked_at' => now(),
            'result_note' => $note,
        ]);

        return $this->success(null, 'Acknowledged');
    }

    private function accountPayload(ChildVirtualAccount $account): array
    {
        return [
            'external_customer_id' => $account->external_customer_id,
            'provider' => $account->provider,
            'account_number' => $account->account_number,
            'bank_name' => $account->bank_name,
            'account_name' => $account->account_name,
        ];
    }
}
