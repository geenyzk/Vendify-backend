<?php

namespace App\Http\Controllers;

use App\Classes\SerivceControl\ServiceControlService;
use App\Classes\TransactionService;
use App\HttpResponse;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\AppNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class WalletTransferController extends Controller
{
    use HttpResponse;

    private function resolveRecipient(string $identifier): ?User
    {
        // Grouped so `is_active` scopes the whole OR, not just the last arm
        // (an ungrouped orWhere chain would let an inactive user match via
        // username/email and only require is_active on the phone check).
        return User::where(function ($query) use ($identifier) {
            $query->where('username', $identifier)
                ->orWhere('email', $identifier)
                ->orWhere('phone', $identifier);
        })
            ->where('is_active', true)
            ->first();
    }

    /**
     * Look up a recipient by username/email/phone before sending — lets the
     * customer confirm they're paying the right person, the same way a bank
     * transfer app shows the beneficiary's name before you commit.
     */
    public function lookup(Request $request): JsonResponse
    {
        $validated = $request->validate(['identifier' => 'required|string']);

        $recipient = $this->resolveRecipient($validated['identifier']);

        if (!$recipient || $recipient->id === Auth::id()) {
            return $this->fail([], 'No user found with that username, email, or phone number.', 404);
        }

        return $this->success([
            'id' => $recipient->id,
            'username' => $recipient->username,
            'fullname' => $recipient->fullname,
        ]);
    }

    /**
     * Instant wallet-to-wallet transfer — an internal ledger move, no real
     * money leaves the platform, so (unlike withdrawals) this needs no
     * admin review, just the transaction PIN every other spend requires.
     */
    public function send(Request $request): JsonResponse
    {
        $settings = Setting::first();
        $min = (float) ($settings?->wallet_transfer_min ?? 50);
        $max = (float) ($settings?->wallet_transfer_max ?? 1000000);

        $validated = $request->validate([
            'identifier' => 'required|string',
            'amount' => "required|numeric|min:{$min}|max:{$max}",
            'pin' => 'required|string',
            'note' => 'nullable|string|max:255',
        ]);

        $sender = Auth::user();
        $recipient = $this->resolveRecipient($validated['identifier']);

        if (!$recipient || $recipient->id === $sender->id) {
            return $this->fail([], 'No user found with that username, email, or phone number.', 404);
        }

        if (!ServiceControlService::verify($sender->id, $validated['pin'])) {
            return $this->fail(['pin' => ['Invalid pin']], '', 422);
        }

        if ((float) $sender->wallet_balance < (float) $validated['amount']) {
            return $this->fail([], 'Insufficient wallet balance.', 422);
        }

        try {
            $result = DB::transaction(function () use ($sender, $recipient, $validated) {
                $amount = (float) $validated['amount'];
                $note = $validated['note'] ?? null;

                $outgoing = TransactionService::fundUser(
                    $sender,
                    $amount,
                    'debit',
                    $note ?? "Transfer to {$recipient->username}",
                    'wallet_transfer_out',
                    'wallet',
                    $recipient->username,
                );

                $incoming = TransactionService::fundUser(
                    $recipient,
                    $amount,
                    'credit',
                    $note ?? "Transfer from {$sender->username}",
                    'wallet_transfer_in',
                    'wallet',
                    $sender->username,
                    $outgoing['transaction_reference'],
                );

                Transaction::where('transaction_reference', $outgoing['transaction_reference'])
                    ->update(['related_reference' => $incoming['transaction_reference']]);

                $outgoing['related_reference'] = $incoming['transaction_reference'];

                return $outgoing;
            });
        } catch (\Throwable $e) {
            return $this->fail([], 'Could not complete this transfer. Please try again.', 500);
        }

        $recipient->notify(new AppNotification(
            'wallet_transfer_in',
            'Money received',
            "You received ₦{$validated['amount']} from @{$sender->username}.",
        ));

        return $this->success($result, 'Transfer successful');
    }
}
