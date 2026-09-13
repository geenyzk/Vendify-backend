<?php

namespace App\Http\Controllers;

use App\Classes\AdminNotifier;
use App\Classes\TransactionService;
use App\HttpResponse;
use App\Models\AirtimeToCashRequest;
use App\Models\Network;
use App\Models\User;
use App\Notifications\AppNotification;
use App\Services\AirtimeToCash\AirtimeToCashProviderService;
use App\Services\AirtimeToCashAvailabilityService;
use App\Services\AirtimeToCashSettlementService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class AirtimeToCashController extends Controller
{
    use HttpResponse;

    public function quote(Request $request): JsonResponse
    {
        $input = $request->validate([
            'network_id' => 'nullable|integer|min:1',
            'network' => 'required_without:network_id|string',
            'amount' => 'required|numeric|gt:0',
        ]);
        $policy = app(AirtimeToCashAvailabilityService::class);
        $network = $policy->resolve(isset($input['network_id']) ? (int) $input['network_id'] : null, $input['network'] ?? null);
        $quote = $policy->inspect($network, (float) $input['amount']);

        return response()->json([
            'success' => $quote['available'], 'data' => $quote,
            'message' => $quote['reason'] ?? 'Quote calculated',
            'type' => $quote['available'] ? 'success' : 'error',
        ], $quote['available'] ? 200 : 422)->header('Cache-Control', 'private, no-store');
    }

    public function catalog(): JsonResponse
    {
        $policy = app(AirtimeToCashAvailabilityService::class);
        $automation = app(AirtimeToCashProviderService::class);

        // Top-level availability fields describe manual conversion; automated_* is independent of them.
        return $this->success(Network::orderBy('name')->get()->map(function ($network) use ($policy, $automation) {
            $automated = $automation->inspect($network);

            return [
                'id' => $network->id, 'name' => $network->name,
                'airtime_to_cash_active' => $network->airtime_to_cash_active,
                'airtime_to_cash_destination_number' => $network->airtime_to_cash_destination_number,
                'airtime_to_cash_min' => $network->airtime_to_cash_min,
                'airtime_to_cash_max' => $network->airtime_to_cash_max,
                ...$policy->inspect($network),
                'automated_available' => $automated['available'],
                'automated_reason' => $automated['reason'],
            ];
        }))->header('Cache-Control', 'private, no-store');
    }

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
            'network_id' => 'nullable|integer',
            'idempotency_key' => 'sometimes|uuid',
            // Kept for clients deployed before network_id was introduced.
            'network' => 'required_without:network_id|string',
            'amount' => 'required|numeric|min:1',
            'sender_phone' => ['required', 'regex:/^0[789][0-9]{9}$/'],
            'quoted_payout' => 'nullable|numeric|gt:0',
            'quoted_destination_number' => 'nullable|string|max:20',
            'proof_image' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:2048',
        ]);

        $policy = app(AirtimeToCashAvailabilityService::class);
        $network = $policy->resolve(isset($validated['network_id']) ? (int) $validated['network_id'] : null, $validated['network'] ?? null);
        $quote = $policy->inspect($network, (float) $validated['amount']);
        if (! $quote['available']) {
            throw ValidationException::withMessages(['network' => [$quote['reason']]]);
        }
        if (isset($validated['quoted_payout']) && round((float) $validated['quoted_payout'], 2) !== $quote['payout_amount']) {
            return $this->fail([], 'The conversion rate changed. Review a fresh quote before submitting.', 422);
        }
        if (isset($validated['quoted_destination_number']) && $validated['quoted_destination_number'] !== $quote['destination_number']) {
            return $this->fail([], 'The destination number changed. Review a fresh quote before transferring airtime.', 422);
        }
        $payoutAmount = $quote['payout_amount'];

        $startKey = isset($validated['idempotency_key'])
            ? hash('sha256', Auth::id().':manual:'.$validated['idempotency_key']) : null;
        $atc = DB::transaction(function () use ($startKey, $network, $validated, $payoutAmount, $request) {
            User::lockForUpdate()->findOrFail(Auth::id());
            if ($startKey && ($existing = AirtimeToCashRequest::where('start_key', $startKey)->first())) {
                if ((int) $existing->network_id !== (int) $network->id || (float) $existing->amount !== (float) $validated['amount']
                    || $existing->sender_phone !== $validated['sender_phone']) {
                    throw ValidationException::withMessages(['idempotency_key' => ['This submission reference belongs to another conversion.']]);
                }
                return $existing;
            }
            $proofPath = $request->hasFile('proof_image')
                ? url(Storage::url($request->file('proof_image')->store('airtime-to-cash-proofs', 'public'))) : null;

            return AirtimeToCashRequest::create([
                'start_key' => $startKey,
                'user_id' => Auth::id(),
                'network_id' => $network->id,
                'processing_mode' => 'manual',
                'network' => $network->name,
                'amount' => $validated['amount'],
                'sender_phone' => $validated['sender_phone'],
                'destination_number' => $network->airtime_to_cash_destination_number,
                'payout_amount' => $payoutAmount,
                'status' => 'pending',
                'proof_image' => $proofPath,
                'transaction_reference' => TransactionService::generateTransactionReference(),
            ]);
        });

        if ($atc->wasRecentlyCreated) {
            AdminNotifier::notifyAirtimeToCashPending($atc);
        }

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

        return $this->success($requests->map(fn ($item) => [...$item->toArray(), 'provider' => $item->provider]));
    }

    /** Atomically credit the payout and mark this request approved. */
    public function approve(AirtimeToCashRequest $atc, AirtimeToCashSettlementService $settlement): JsonResponse
    {
        try {
            $atc = $settlement->settle((int) $atc->id, (string) Auth::id());
        } catch (DomainException $e) {
            return $this->fail([], $e->getMessage(), 422);
        }

        $user = $atc->user;
        try {
            $user->notify(new AppNotification(
                'airtime_to_cash_approved',
                'Airtime to cash approved',
                "Your {$atc->network} airtime-to-cash request was approved — ₦{$atc->payout_amount} credited to your wallet.",
            ));
        } catch (Throwable $e) {
            Log::warning('Airtime-to-cash approval notification failed after settlement', [
                'request_id' => $atc->id,
                'user_id' => $atc->user_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $this->success($atc->fresh(), 'Request approved and wallet credited');
    }

    public function reject(Request $request, AirtimeToCashRequest $atc): JsonResponse
    {
        $validated = $request->validate(['reason' => 'required|string|max:255']);

        $atc = DB::transaction(function () use ($atc, $validated) {
            $locked = AirtimeToCashRequest::query()->lockForUpdate()->findOrFail($atc->id);
            if ($locked->processing_mode === 'provider' || $locked->status !== 'pending'
                || $locked->payoutTransaction()->exists()
                || $locked->payout_transaction_reference) {
                return null;
            }

            $locked->update([
                'status' => 'rejected',
                'rejection_reason' => $validated['reason'],
                'reviewed_by' => Auth::id(),
                'reviewed_at' => now(),
            ]);

            return $locked->fresh();
        });

        if (! $atc) {
            return $this->fail([], 'This request has already been reviewed.', 422);
        }

        $user = User::find($atc->user_id);
        $user?->notify(new AppNotification(
            'airtime_to_cash_rejected',
            'Airtime to cash rejected',
            "Your {$atc->network} airtime-to-cash request was rejected: {$validated['reason']}",
        ));

        return $this->success($atc->fresh(), 'Request rejected');
    }
}
