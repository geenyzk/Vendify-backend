<?php

namespace App\Services\AiManager\Tools;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * A live health snapshot of the platform: user counts, wallet liability, and
 * today's transaction volume broken down by status. The assistant's default
 * starting point for "how is the site doing?" questions.
 */
class GetSiteStatsTool extends AiTool
{
    public function name(): string
    {
        return 'get_site_stats';
    }

    public function description(): string
    {
        return 'Get an overall health snapshot of the platform right now: total and active user counts, total wallet balance held (liability), and today\'s transaction counts and value broken down by status (success, fail, pending). Use this for high-level "how is the site doing" questions.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false];
    }

    public function handle(array $arguments, User $actor): array
    {
        $startOfToday = Carbon::today();

        $todayByStatus = Transaction::query()
            ->where('created_at', '>=', $startOfToday)
            ->selectRaw('status, COUNT(*) as count, COALESCE(SUM(amount), 0) as volume')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $statusFigure = fn (string $status) => [
            'count' => (int) ($todayByStatus[$status]->count ?? 0),
            'value' => (float) ($todayByStatus[$status]->volume ?? 0),
        ];

        return [
            'as_of' => now()->toDateTimeString(),
            'users' => [
                'total' => User::count(),
                'active' => User::where('status', User::STATUS_ACTIVE)->count(),
                'signed_up_today' => User::where('created_at', '>=', $startOfToday)->count(),
                'wallet_liability' => round((float) User::sum('wallet_balance'), 2),
            ],
            'transactions_today' => [
                'success' => $statusFigure('success'),
                'pending' => $statusFigure('pending'),
                'fail' => $statusFigure('fail'),
            ],
            'currency' => 'NGN',
        ];
    }
}
