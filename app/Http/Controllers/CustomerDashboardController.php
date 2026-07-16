<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerDashboardController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $now = now();
        $creditSql = "transaction_type IN ('wallet_funding','wallet_transfer_in') OR (transaction_type IN ('manual_funding','wallet_withdrawal') AND plan_type = 'credit')";

        $todayStart = $now->copy()->startOfDay();
        $monthly = Transaction::where('user_id', $user->id)
            ->where('created_at', '>=', $now->copy()->startOfMonth())
            ->selectRaw("SUM(CASE WHEN status = 'success' AND ($creditSql) THEN amount ELSE 0 END) AS deposits")
            ->selectRaw("SUM(CASE WHEN status = 'success' AND NOT ($creditSql) THEN amount ELSE 0 END) AS purchases")
            ->selectRaw("SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) AS successful")
            ->selectRaw("SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending")
            ->selectRaw("SUM(CASE WHEN status = 'fail' THEN 1 ELSE 0 END) AS failed")
            ->selectRaw("SUM(CASE WHEN created_at >= ? AND status = 'success' AND NOT ($creditSql) THEN amount ELSE 0 END) AS today_spend", [$todayStart])
            ->selectRaw("SUM(CASE WHEN created_at >= ? AND status = 'success' AND transaction_type = 'data_subscription' THEN quantity ELSE 0 END) AS today_data_gb", [$todayStart])
            ->first();

        $chart = Transaction::where('user_id', $user->id)
            ->where('status', 'success')
            ->where('created_at', '>=', $now->copy()->subDays(30)->startOfDay())
            ->selectRaw('DATE(created_at) AS date, SUM(amount) AS total_amount')
            ->groupBy(DB::raw('DATE(created_at)'))->orderBy('date')->get();

        $transactions = Transaction::where('user_id', $user->id)->latest()->limit(6)->get([
            'id', 'user_id', 'transaction_type', 'provider', 'amount', 'status',
            'transaction_reference', 'receiver', 'account_or_phone', 'plan_type',
            'quantity', 'created_at',
        ]);

        $user->load('role.permissions');
        $user->setAppends(['has_pin']);

        return $this->success([
            'user' => $user,
            'transactions' => $transactions,
            'summary' => [
                'monthly_deposits' => (float) ($monthly->deposits ?? 0),
                'monthly_purchases' => (float) ($monthly->purchases ?? 0),
                'monthly_successful' => (int) ($monthly->successful ?? 0),
                'monthly_pending' => (int) ($monthly->pending ?? 0),
                'monthly_failed' => (int) ($monthly->failed ?? 0),
                'today_spend' => (float) ($monthly->today_spend ?? 0),
                'today_data_gb' => (float) ($monthly->today_data_gb ?? 0),
                'tx_amount_30d' => $chart,
            ],
        ]);
    }
}
