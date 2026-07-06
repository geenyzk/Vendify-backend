<?php

namespace App\Http\Controllers;

use App\Classes\AdminNotifier;
use App\Classes\TransactionService;
use App\HttpResponse;
use App\Models\AirtimeToCashRequest;
use App\Models\Discount;
use App\Models\Network;
use App\Models\User;
use App\Notifications\AppNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class AirtimeToCashController extends Controller
{
    use HttpResponse;

    /**
     * Customer submits airtime already transferred (via their network's
     * transfer USSD) to the platform's number for this network, declaring
     * the amount and the phone it was sent from. Nothing is credited yet —
     * this only creates a pending request for an admin to verify and
     * approve or reject (see approve()/reject()).
     */
    public function submit(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'network' => [
                'required',
                'string',
                function ($attribute, $value, $fail) {
                    $network = Network::where('name', $value)->where('airtime_to_cash_active', true)->first();
                    if (!$network) {
                        $fail('Airtime to cash is not available for this network yet.');
                    }
                },
            ],
            'amount' => 'required|numeric|min:1',
            'sender_phone' => 'required|string|max:20',
            'proof_image' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:2048',
        ]);

        $network = Network::where('name', $validated['network'])->where('airtime_to_cash_active', true)->first();

        if ((float) $validated['amount'] < (float) $network->airtime_to_cash_min || (float) $validated['amount'] > (float) $network->airtime_to_cash_max) {
            return $this->fail([], "Amount must be between {$network->airtime_to_cash_min} and {$network->airtime_to_cash_max}.", 422);
        }

        $rate = Discount::findApplicable('airtimeToCash', $validated['network']);
        if (!$rate) {
            return $this->fail([], 'Airtime to cash is not available for this network yet.', 422);
        }
        $payoutAmount = Discount::getDiscountedAmount((float) $validated['amount'], 'airtimeToCash', $validated['network']);

        $proofPath = null;
        if ($request->hasFile('proof_image')) {
            $proofPath = url(Storage::url($request->file('proof_image')->store('airtime-to-cash-proofs', 'public')));
        }

        $atc = AirtimeToCashRequest::create([
            'user_id' => Auth::id(),
            'network' => $validated['network'],
            'amount' => $validated['amount'],
            'sender_phone' => $validated['sender_phone'],
            'destination_number' => $network->airtime_to_cash_destination_number,
            'payout_amount' => $payoutAmount,
            'status' => 'pending',
            'proof_image' => $proofPath,
            'transaction_reference' => TransactionService::generateTransactionReference(),
        ]);

        AdminNotifier::notifyAirtimeToCashPending($atc);

        return $this->success($atc, 'Request submitted for review', 201);
    }

    /**
     * The current user's own submissions, newest first — deliberately
     * scoped to Auth::id() rather than served via the generic Universal
     * Table API, which has no per-row ownership check.
     */
    public function myRequests(): JsonResponse
    {
        $requests = AirtimeToCashRequest::where('user_id', Auth::id())->latest()->get();

        return $this->success($requests);
    }

    /**
     * Every submission, for the admin review queue. Gated by the
     * `airtime_to_cash` permission (see routes/api.php), not just
     * user_type:admin, since this is a distinct reviewer capability.
     */
    public function adminIndex(): JsonResponse
    {
        $requests = AirtimeToCashRequest::with(['user:id,username,email,phone', 'reviewer:id,username'])
            ->latest()
            ->get();

        return $this->success($requests);
    }

    /**
     * Credits the customer's wallet with the already-computed payout and
     * records it as a normal ledger Transaction (via the same fundUser()
     * helper the admin "fund user" action uses), then marks this request
     * reviewed. Wrapped atomically inside fundUser()'s own DB transaction.
     */
    public function approve(AirtimeToCashRequest $atc): JsonResponse
    {
        if ($atc->status !== 'pending') {
            return $this->fail([], 'This request has already been reviewed.', 422);
        }

        $user = User::findOrFail($atc->user_id);
        $result = TransactionService::fundUser(
            $user,
            (float) $atc->payout_amount,
            'credit',
            'Airtime to cash - ' . strtoupper($atc->network) . ' - ' . $atc->sender_phone,
        );

        $atc->update([
            'status' => 'approved',
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
            'payout_transaction_reference' => $result['transaction_reference'],
        ]);

        $user->notify(new AppNotification(
            'airtime_to_cash_approved',
            'Airtime to cash approved',
            "Your {$atc->network} airtime-to-cash request was approved — ₦{$atc->payout_amount} credited to your wallet.",
        ));

        return $this->success($atc->fresh(), 'Request approved and wallet credited');
    }

    public function reject(Request $request, AirtimeToCashRequest $atc): JsonResponse
    {
        if ($atc->status !== 'pending') {
            return $this->fail([], 'This request has already been reviewed.', 422);
        }

        $validated = $request->validate(['reason' => 'required|string|max:255']);

        $atc->update([
            'status' => 'rejected',
            'rejection_reason' => $validated['reason'],
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
        ]);

        $user = User::find($atc->user_id);
        $user?->notify(new AppNotification(
            'airtime_to_cash_rejected',
            'Airtime to cash rejected',
            "Your {$atc->network} airtime-to-cash request was rejected: {$validated['reason']}",
        ));

        return $this->success($atc->fresh(), 'Request rejected');
    }
}
