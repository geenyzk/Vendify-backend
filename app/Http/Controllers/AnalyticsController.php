<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\User;
use App\Support\PerformanceCache;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

/**
 * @group Admin Analytics
 */
class AnalyticsController extends Controller
{
    private const FUNDING_TYPES = ['wallet_funding', 'manual_funding'];

    private const SERVICE_LABELS = [
        'airtime_recharge' => 'Airtime',
        'data_subscription' => 'Data',
        'cable_subscription' => 'Cable',
        'electric_bill' => 'Electricity',
        'exam' => 'Exam PIN',
        'betting_funding' => 'Betting',
        'airtime_pin' => 'Airtime Pin',
        'data_pin' => 'Data Pin',
        'wallet_funding' => 'Wallet Funding',
        'manual_funding' => 'Manual Funding',
        'bulksms' => 'Bulk SMS',
    ];

    /**
     * Analytics for the admin dashboard over a given date range.
     * Defaults to the last 30 days when no range is supplied.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        $endDate = isset($validated['end_date'])
            ? Carbon::parse($validated['end_date'])->endOfDay()
            : Carbon::now()->endOfDay();

        $startDate = isset($validated['start_date'])
            ? Carbon::parse($validated['start_date'])->startOfDay()
            : $endDate->copy()->subDays(29)->startOfDay();

        $analytics = Cache::remember(
            PerformanceCache::analyticsKey($startDate->toDateString(), $endDate->toDateString()),
            now()->addMinutes(5),
            fn () => [
            'period' => [
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
            ],
            'summary' => $this->buildSummary($startDate, $endDate),
            'revenue_over_time' => $this->buildRevenueOverTime($startDate, $endDate),
            'transactions_over_time' => $this->buildTransactionsOverTime($startDate, $endDate),
            'by_service_type' => $this->buildByServiceType($startDate, $endDate),
            'by_provider' => $this->buildByProvider($startDate, $endDate),
            'signups_over_time' => $this->buildSignupsOverTime($startDate, $endDate),
            'funding_vs_spend' => $this->buildFundingVsSpend($startDate, $endDate),
            'top_customers' => $this->buildTopCustomers($startDate, $endDate),
            ],
        );

        return $this->success($analytics);
    }

    private function dateRange(Carbon $start, Carbon $end): array
    {
        $days = collect();
        $cursor = $start->copy()->startOfDay();
        $lastDay = $end->copy()->startOfDay();
        while ($cursor->lte($lastDay)) {
            $days->push($cursor->toDateString());
            $cursor->addDay();
        }
        return $days->all();
    }

    private function buildSummary(Carbon $start, Carbon $end): array
    {
        $base = Transaction::whereBetween('created_at', [$start, $end]);

        $totalTransactions = (clone $base)->count();
        $successCount = (clone $base)->where('status', 'success')->count();
        $failedCount = (clone $base)->where('status', 'fail')->count();
        $pendingCount = (clone $base)->where('status', 'pending')->count();

        $totalRevenue = (clone $base)->where('status', 'success')
            ->whereNotIn('transaction_type', self::FUNDING_TYPES)
            ->sum('amount');

        // Profit is measured only over sales that carry a recorded vendor cost
        // (see VendorBase::resolveCost) — older/untracked sales are excluded
        // rather than counted as pure margin. profit = tracked revenue − cost.
        $costBase = (clone $base)->where('status', 'success')
            ->whereNotIn('transaction_type', self::FUNDING_TYPES)
            ->whereNotNull('cost');
        $totalCost = (float) (clone $costBase)->sum('cost');
        $trackedRevenue = (float) (clone $costBase)->sum('amount');
        $totalProfit = $trackedRevenue - $totalCost;

        // Data volume sold, in GB — quantity is recorded from the plan size at
        // purchase (see VendorBase::resolveDataGb).
        $dataGb = (float) (clone $base)->where('status', 'success')
            ->where('transaction_type', 'data_subscription')
            ->sum('quantity');

        return [
            'total_revenue' => (float) $totalRevenue,
            'total_cost' => $totalCost,
            'total_profit' => $totalProfit,
            'data_gb' => round($dataGb, 2),
            'total_transactions' => $totalTransactions,
            'successful_transactions' => $successCount,
            'failed_transactions' => $failedCount,
            'pending_transactions' => $pendingCount,
            'success_rate' => $totalTransactions > 0 ? round($successCount / $totalTransactions * 100, 2) : 0.0,
            'average_transaction_value' => $successCount > 0 ? round($totalRevenue / $successCount, 2) : 0.0,
            'new_signups' => User::whereBetween('created_at', [$start, $end])->count(),
        ];
    }

    private function buildRevenueOverTime(Carbon $start, Carbon $end): array
    {
        $rows = Transaction::selectRaw('DATE(created_at) as date, SUM(amount) as total')
            ->whereBetween('created_at', [$start, $end])
            ->where('status', 'success')
            ->whereNotIn('transaction_type', self::FUNDING_TYPES)
            ->groupBy(DB::raw('DATE(created_at)'))
            ->pluck('total', 'date');

        $labels = $this->dateRange($start, $end);

        return [
            'labels' => $labels,
            'values' => array_map(fn ($date) => (float) ($rows[$date] ?? 0), $labels),
        ];
    }

    private function buildTransactionsOverTime(Carbon $start, Carbon $end): array
    {
        $rows = Transaction::real()->selectRaw('DATE(created_at) as date, status, COUNT(*) as total')
            ->whereBetween('created_at', [$start, $end])
            ->groupBy(DB::raw('DATE(created_at)'), 'status')
            ->get()
            ->groupBy('date');

        $labels = $this->dateRange($start, $end);
        $series = ['success' => [], 'fail' => [], 'pending' => []];

        foreach ($labels as $date) {
            $dayRows = $rows->get($date, collect());
            foreach (array_keys($series) as $status) {
                $match = $dayRows->firstWhere('status', $status);
                $series[$status][] = $match ? (int) $match->total : 0;
            }
        }

        return array_merge(['labels' => $labels], $series);
    }

    private function buildByServiceType(Carbon $start, Carbon $end): array
    {
        $rows = Transaction::real()->selectRaw('transaction_type, SUM(amount) as revenue, COUNT(*) as count')
            ->whereBetween('created_at', [$start, $end])
            ->where('status', 'success')
            ->groupBy('transaction_type')
            ->get()
            ->keyBy('transaction_type');

        $result = [];
        foreach (self::SERVICE_LABELS as $type => $label) {
            $row = $rows->get($type);
            $result[] = [
                'type' => $type,
                'label' => $label,
                'revenue' => (float) ($row->revenue ?? 0),
                'count' => (int) ($row->count ?? 0),
            ];
        }

        return $result;
    }

    private function buildByProvider(Carbon $start, Carbon $end): array
    {
        return Transaction::real()->selectRaw('provider, SUM(amount) as revenue, COUNT(*) as count')
            ->whereBetween('created_at', [$start, $end])
            ->where('status', 'success')
            ->whereNotNull('provider')
            ->groupBy('provider')
            ->orderByDesc('revenue')
            ->get()
            ->map(fn ($row) => [
                'provider' => $row->provider,
                'revenue' => (float) $row->revenue,
                'count' => (int) $row->count,
            ])
            ->all();
    }

    private function buildSignupsOverTime(Carbon $start, Carbon $end): array
    {
        $rows = User::selectRaw('DATE(created_at) as date, COUNT(*) as total')
            ->whereBetween('created_at', [$start, $end])
            ->groupBy(DB::raw('DATE(created_at)'))
            ->pluck('total', 'date');

        $labels = $this->dateRange($start, $end);

        return [
            'labels' => $labels,
            'values' => array_map(fn ($date) => (int) ($rows[$date] ?? 0), $labels),
        ];
    }

    private function buildFundingVsSpend(Carbon $start, Carbon $end): array
    {
        $base = Transaction::real()->whereBetween('created_at', [$start, $end])->where('status', 'success');

        return [
            'total_funding' => (float) (clone $base)->whereIn('transaction_type', self::FUNDING_TYPES)->sum('amount'),
            'total_spend' => (float) (clone $base)->whereNotIn('transaction_type', self::FUNDING_TYPES)->sum('amount'),
        ];
    }

    private function buildTopCustomers(Carbon $start, Carbon $end, int $limit = 10): array
    {
        $rows = Transaction::real()->selectRaw('user_id, SUM(amount) as total_spent, COUNT(*) as transaction_count')
            ->whereBetween('created_at', [$start, $end])
            ->where('status', 'success')
            ->whereNotIn('transaction_type', self::FUNDING_TYPES)
            ->groupBy('user_id')
            ->orderByDesc('total_spent')
            ->limit($limit)
            ->get();

        $userIds = $rows->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $users = User::whereIn('id', $userIds)
            ->get(['id', 'fullname', 'email'])
            ->keyBy('id');

        return $rows->map(function ($row) use ($users) {
            $user = $users->get((int) $row->user_id);
            return [
                'user_id' => (int) $row->user_id,
                'name' => $user->fullname ?? 'Unknown',
                'email' => $user->email ?? null,
                'total_spent' => (float) $row->total_spent,
                'transaction_count' => (int) $row->transaction_count,
            ];
        })->all();
    }
}
