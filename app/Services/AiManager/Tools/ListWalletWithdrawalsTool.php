<?php

namespace App\Services\AiManager\Tools;

use App\Models\User;
use App\Models\WalletWithdrawal;

/**
 * The wallet withdrawal (payout) queue, so the assistant can report pending
 * payouts and reference a withdrawal id before proposing review_wallet_withdrawal.
 */
class ListWalletWithdrawalsTool extends AiTool
{
    private const MAX_LIMIT = 50;

    public function name(): string
    {
        return 'list_wallet_withdrawals';
    }

    public function description(): string
    {
        return 'List wallet withdrawal (payout) requests, newest first, optionally filtered by status (pending, completed, rejected, failed). Shows amount, fee, destination bank/account, requester, and status. Use this before proposing to approve or reject a withdrawal.';
    }

    public function permission(): ?string
    {
        return 'wallets';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => ['type' => 'string', 'enum' => ['pending', 'completed', 'rejected', 'failed'], 'description' => 'Optional status filter. Omit for all.'],
                'limit' => ['type' => 'integer', 'description' => 'Max rows (1-50, default 20).'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'status' => 'nullable|in:pending,completed,rejected,failed',
            'limit' => 'nullable|integer|min:1|max:' . self::MAX_LIMIT,
        ];
    }

    public function handle(array $arguments, User $actor): array
    {
        $query = WalletWithdrawal::with('user:id,username,email,phone');

        if (!empty($arguments['status'])) {
            $query->where('status', $arguments['status']);
        }

        $total = (clone $query)->count();
        $pending = (clone $query)->where('status', 'pending')->count();
        $limit = min((int) ($arguments['limit'] ?? 20), self::MAX_LIMIT);

        $rows = $query->latest()->limit($limit)->get()->map(fn (WalletWithdrawal $w) => [
            'id' => $w->id,
            'status' => $w->status,
            'amount' => (float) $w->amount,
            'fee' => (float) $w->fee,
            'bank_name' => $w->bank_name,
            'account_number' => $w->account_number,
            'account_name' => $w->account_name,
            'user' => $w->user ? ['id' => $w->user->id, 'username' => $w->user->username, 'email' => $w->user->email] : null,
            'created_at' => $w->created_at?->toDateTimeString(),
        ]);

        return [
            'total_matches' => $total,
            'pending_count' => $pending,
            'returned' => $rows->count(),
            'withdrawals' => $rows,
        ];
    }
}
