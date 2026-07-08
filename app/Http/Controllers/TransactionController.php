<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Classes\TransactionPruner;
use App\Classes\TransactionService;
use App\Http\Resources\TransactionResource;
use App\HttpResponse;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TransactionController extends Controller
{
    use HttpResponse;

    /**
 * Transaction Report
 *
 * @group Transactions
 *
 * This endpoint returns a summary of transactions between a given date range.
 * It can also filter transactions by a specific user if the `user_id` is provided.
 *
 * @queryParam start_date date Optional. The start date of the report in YYYY-MM-DD format. Defaults to the first day of the current month. Example: 2025-08-01
 * @queryParam end_date date Optional. The end date of the report in YYYY-MM-DD format. Defaults to today. Example: 2025-08-07
 * @queryParam user_id integer Optional. The ID of the user to filter transactions. Example: 12
 *
 * @response 200 {
 *   "start_date": "2025-08-01",
 *   "end_date": "2025-08-07",
 *   "transactions": {
 *     "total": 50000,
 *     "count": 25,
 *     "breakdown": {
 *       "deposit": 30000,
 *       "withdrawal": 20000
 *     }
 *   }
 * }
 */

    public function report(Request $request)
    {
        try {
            //code...
            Log::info("TRepo..");
            $startDate = Carbon::parse($request->input('start_date', now()->startOfMonth()))->startOfDay();
            $endDate = Carbon::parse($request->input('end_date', now()))->endOfDay();

            $transactions = Transaction::calculateSummary($startDate, $endDate, $request->input("user_id"));


            return response()->json([
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
                'transactions' => $transactions,
            ]);
        } catch (\Throwable $th) {
            //throw $th;
            Log::info($th);
        }

    }

    /**
     * Manually override a transaction's status. Pure metadata correction —
     * does not move any money. Locked once a transaction has been refunded.
     */
    public function updateStatus(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => 'required|in:pending,success,fail',
            'note' => 'nullable|string|max:500',
        ]);

        $transaction = Transaction::find($id);
        if (!$transaction) {
            return $this->fail([], 'Transaction not found', 404);
        }

        if ($transaction->refunded_at) {
            return $this->fail([], 'This transaction has already been refunded and its status is locked.', 422);
        }

        $transaction->status = $validated['status'];
        if (!empty($validated['note'])) {
            $transaction->response_message = trim(
                ($transaction->response_message ? $transaction->response_message . ' | ' : '') .
                "Status set to {$validated['status']} by admin: {$validated['note']}"
            );
        }
        $transaction->save();

        return $this->success(new TransactionResource($transaction->load('user')), 'Transaction status updated');
    }

    /**
     * Refund a successful, wallet-charging transaction. Credits the
     * customer's wallet via TransactionService::fundUser — the same
     * primitive the admin manual-funding tool uses — then locks the
     * original transaction as refunded so it can't be refunded twice.
     */
    public function refund(Request $request, $id)
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $transaction = Transaction::with('user')->find($id);
        if (!$transaction) {
            return $this->fail([], 'Transaction not found', 404);
        }

        if (!$transaction->user) {
            return $this->fail([], 'This transaction has no associated user to refund.', 422);
        }

        if (!in_array($transaction->transaction_type, Transaction::REFUNDABLE_TYPES, true)) {
            return $this->fail([], 'This transaction type cannot be refunded.', 422);
        }

        if ($transaction->status !== 'success') {
            return $this->fail([], 'Only a successful transaction can be refunded.', 422);
        }

        if ($transaction->refunded_at) {
            return $this->fail([], 'This transaction has already been refunded.', 422);
        }

        try {
            DB::transaction(function () use ($transaction, $validated) {
                TransactionService::fundUser(
                    $transaction->user,
                    floatval($transaction->amount),
                    'credit',
                    "Refund for {$transaction->transaction_reference}: {$validated['reason']}"
                );

                $transaction->status = 'fail';
                $transaction->refunded_at = now();
                $transaction->refund_reason = $validated['reason'];
                $transaction->response_message = trim(
                    ($transaction->response_message ? $transaction->response_message . ' | ' : '') .
                    "Refunded by admin: {$validated['reason']}"
                );
                $transaction->save();
            });
        } catch (\Throwable $e) {
            return $this->fail([], 'Refund failed: ' . $e->getMessage(), 500);
        }

        return $this->success(new TransactionResource($transaction->fresh('user')), 'Transaction refunded and wallet credited');
    }

    /**
     * How many success/fail transactions are older than the configured
     * retention window right now — for the "Prune now" confirmation, so an
     * admin sees the impact before deleting anything.
     */
    public function prunePreview()
    {
        return $this->success(['count' => TransactionPruner::previewCount()]);
    }

    /**
     * Delete old success/fail transactions immediately. Clicking this is
     * itself the opt-in, so it runs regardless of the "automatically prune"
     * toggle in Settings > Transaction.
     */
    public function pruneNow()
    {
        $count = TransactionPruner::run(force: true);

        return $this->success(['pruned' => $count], "Pruned {$count} transaction(s)");
    }

}
