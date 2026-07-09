<?php

namespace App\Services\AiManager\Tools;

use App\Models\Transaction;
use App\Models\User;

/**
 * A focused customer profile: identity, wallet, status, and a compact summary
 * of recent transaction activity — enough context to decide whether to fund a
 * wallet or investigate a complaint, without dumping the whole account.
 */
class GetUserTool extends AiTool
{
    public function name(): string
    {
        return 'get_user';
    }

    public function description(): string
    {
        return 'Fetch one customer by id or email: identity, status, wallet balance, and a short summary of their recent transactions (counts by status plus the latest few). Use before proposing a wallet funding or investigating a support issue.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Numeric user id.'],
                'email' => ['type' => 'string', 'description' => 'Customer email.'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'id' => 'nullable|integer',
            'email' => 'nullable|email',
        ];
    }

    public function handle(array $arguments, User $actor): array
    {
        $query = User::query();

        if (!empty($arguments['id'])) {
            $query->where('id', $arguments['id']);
        } elseif (!empty($arguments['email'])) {
            $query->where('email', $arguments['email']);
        } else {
            return ['error' => 'Provide either id or email.'];
        }

        $user = $query->first(['id', 'fullname', 'email', 'phone', 'username', 'status', 'user_type', 'wallet_balance', 'referral_balance', 'is_verified', 'created_at', 'last_login_at']);

        if (!$user) {
            return ['found' => false];
        }

        $byStatus = Transaction::where('user_id', $user->id)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $recent = Transaction::where('user_id', $user->id)
            ->latest('id')
            ->limit(5)
            ->get(['id', 'transaction_type', 'amount', 'status', 'transaction_reference', 'created_at'])
            ->map(fn (Transaction $t) => [
                'id' => $t->id,
                'type' => $t->transaction_type,
                'amount' => (float) $t->amount,
                'status' => $t->status,
                'reference' => $t->transaction_reference,
                'created_at' => optional($t->created_at)->toDateTimeString(),
            ]);

        return [
            'found' => true,
            'user' => [
                'id' => $user->id,
                'name' => $user->fullname,
                'email' => $user->email,
                'phone' => $user->phone,
                'username' => $user->username,
                'status' => $user->status,
                'user_type' => $user->user_type,
                'is_verified' => (bool) $user->is_verified,
                'wallet_balance' => (float) $user->wallet_balance,
                'referral_balance' => (float) $user->referral_balance,
                'joined' => optional($user->created_at)->toDateString(),
                'last_login_at' => optional($user->last_login_at)->toDateTimeString(),
            ],
            'transaction_summary' => [
                'success' => (int) ($byStatus['success'] ?? 0),
                'pending' => (int) ($byStatus['pending'] ?? 0),
                'fail' => (int) ($byStatus['fail'] ?? 0),
            ],
            'recent_transactions' => $recent,
        ];
    }
}
