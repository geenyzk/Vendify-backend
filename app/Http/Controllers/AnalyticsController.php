<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Powers the admin dashboard's date-range panels (revenue, transaction
 * volume, service/provider breakdown, funding vs spend, top customers).
 * See vtu_2/src/features/admin/pages/dashboardService.ts for the exact
 * response contract this must match — the frontend was already built
 * against this shape.
 */
class AnalyticsController extends Controller
{
    // wallet_funding/manual_funding are money coming IN (a top-up) — kept
    // out of "revenue" (which means money the platform earned from a sale)
    // and reported separately via funding_vs_spend instead.
    private const FUNDING_TYPES = ['wallet_funding', 'manual_funding'];

    private const SERVICE_LABELS = [
        'airtime_recharge' => 'Airtime',
        'data_subscription' => 'Data',
        'cable_subscription' => 'Cable',
        'electric_bill' => 'Electricity',
        'exam' => 'Exam PIN',
        'betting_funding' => 'Betting',
        'airtime_pin' => 'Airtime PIN',
        'data_pin' => 'Data PIN',
        'bulksms' => 'Bulk SMS',
    ];

    public function index(Request $request)
    {
        $end = $request->filled('end_date')
            ? Carbon::parse($request->input('end_date'))->endOfDay()
            : now()->endOfDay();
        $start = $request->filled('start_date')
            ? Carbon::parse($request->input('start_date'))->startOfDay()
            : $end->copy()->subDays(29)->startOfDay();

        $base = fn () => Transaction::whereBetween('created_at', [$start, $end]);

        $totalTransactions = $base()->count();
        $successfulTransactions = $base()->where('status', 'success')->count();
        $failedTransactions = $base()->where('status', 'fail')->count();
        $pendingTransactions = $base()->where('status', 'pending')->count();

        $revenueQuery = fn () => $base()->where('status', 'success')->whereNotIn('transaction_type', self::FUNDING_TYPES);
        $totalRevenue = (float) $revenueQuery()->sum('amount');
        $avgTransactionValue = $successfulTransactions > 0
            ? (float) $base()->where('status', 'success')->avg('amount')
            : 0.0;

        $newSignups = User::whereBetween('created_at', [$start, $end])->count();

        return $this->success([
            'period' => [
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
            ],
            'summary' => [
                'total_revenue' => $totalRevenue,
                'total_transactions' => $totalTransactions,
                'successful_transactions' => $successfulTransactions,
                'failed_transactions' => $failedTransactions,
                'pending_transactions' => $pendingTransactions,
                'success_rate' => $totalTransactions > 0
                    ? round(($successfulTransactions / $totalTransactions) * 100, 1)
                    : 0,
                'average_transaction_value' => round($avgTransactionValue, 2),
                'new_signups' => $newSignups,
            ],
            'revenue_over_time' => $this->dailySeries($revenueQuery(), $start, $end, 'SUM(amount) as value'),
            'transactions_over_time' => $this->transactionsOverTime($start, $end),
            'by_service_type' => $this->byServiceType($start, $end),
            'by_provider' => $this->byProvider($start, $end),
            'signups_over_time' => $this->dailySeries(
                User::whereBetween('created_at', [$start, $end]),
                $start,
                $end,
                'COUNT(*) as value',
            ),
            'funding_vs_spend' => [
                'total_funding' => (float) $base()->where('status', 'success')->whereIn('transaction_type', self::FUNDING_TYPES)->sum('amount'),
                'total_spend' => $totalRevenue,
            ],
            'top_customers' => $this->topCustomers($start, $end),
        ]);
    }

    /**
     * @return array{labels: array<string>, values: array<float>}
     */
    private function dailySeries($query, Carbon $start, Carbon $end, string $selectExpr): array
    {
        $rows = $query
            ->selectRaw("DATE(created_at) as day, {$selectExpr}")
            ->groupBy('day')
            ->pluck('value', 'day');

        $labels = [];
        $values = [];
        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $key = $date->toDateString();
            $labels[] = $key;
            $values[] = (float) ($rows[$key] ?? 0);
        }

        return ['labels' => $labels, 'values' => $values];
    }

    private function transactionsOverTime(Carbon $start, Carbon $end): array
    {
        $rows = Transaction::whereBetween('created_at', [$start, $end])
            ->selectRaw('DATE(created_at) as day, status, COUNT(*) as total')
            ->groupBy('day', 'status')
            ->get()
            ->groupBy('day');

        $labels = [];
        $success = [];
        $fail = [];
        $pending = [];
        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $key = $date->toDateString();
            $labels[] = $key;
            $dayRows = $rows->get($key, collect());
            $success[] = (int) ($dayRows->firstWhere('status', 'success')->total ?? 0);
            $fail[] = (int) ($dayRows->firstWhere('status', 'fail')->total ?? 0);
            $pending[] = (int) ($dayRows->firstWhere('status', 'pending')->total ?? 0);
        }

        return ['labels' => $labels, 'success' => $success, 'fail' => $fail, 'pending' => $pending];
    }

    private function byServiceType(Carbon $start, Carbon $end): array
    {
        $rows = Transaction::whereBetween('created_at', [$start, $end])
            ->where('status', 'success')
            ->whereNotIn('transaction_type', self::FUNDING_TYPES)
            ->selectRaw('transaction_type, SUM(amount) as revenue, COUNT(*) as count')
            ->groupBy('transaction_type')
            ->get();

        return $rows->map(fn ($row) => [
            'type' => $row->transaction_type,
            'label' => self::SERVICE_LABELS[$row->transaction_type] ?? $row->transaction_type,
            'revenue' => (float) $row->revenue,
            'count' => (int) $row->count,
        ])->values()->all();
    }

    private function byProvider(Carbon $start, Carbon $end): array
    {
        $rows = Transaction::whereBetween('created_at', [$start, $end])
            ->where('status', 'success')
            ->whereNotIn('transaction_type', self::FUNDING_TYPES)
            ->whereNotNull('provider')
            ->selectRaw('provider, SUM(amount) as revenue, COUNT(*) as count')
            ->groupBy('provider')
            ->orderByDesc('revenue')
            ->get();

        return $rows->map(fn ($row) => [
            'provider' => $row->provider,
            'revenue' => (float) $row->revenue,
            'count' => (int) $row->count,
        ])->values()->all();
    }

    private function topCustomers(Carbon $start, Carbon $end): array
    {
        $rows = DB::table('transactions')
            ->join('users', 'users.id', '=', 'transactions.user_id')
            ->whereBetween('transactions.created_at', [$start, $end])
            ->where('transactions.status', 'success')
            ->whereNotIn('transactions.transaction_type', self::FUNDING_TYPES)
            ->selectRaw('users.id as user_id, users.fullname as name, users.email as email, SUM(transactions.amount) as total_spent, COUNT(*) as transaction_count')
            ->groupBy('users.id', 'users.fullname', 'users.email')
            ->orderByDesc('total_spent')
            ->limit(10)
            ->get();

        return $rows->map(fn ($row) => [
            'user_id' => (int) $row->user_id,
            'name' => $row->name,
            'email' => $row->email,
            'total_spent' => (float) $row->total_spent,
            'transaction_count' => (int) $row->transaction_count,
        ])->values()->all();
    }
}
