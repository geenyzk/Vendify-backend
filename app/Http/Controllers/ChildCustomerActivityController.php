<?php

namespace App\Http\Controllers;

use App\HttpResponse;
use App\Models\ChildInstance;
use App\Models\ChildTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChildCustomerActivityController extends Controller
{
    use HttpResponse;

    /** Successful service-purchase states emitted by supported child apps. */
    public const ACTIVE_STATUSES = ['success', 'successful', 'completed'];

    /** Balance movement and internal bookkeeping are not service purchases. */
    public const EXCLUDED_TYPES = [
        'funding', 'wallet_funding', 'manual_funding', 'wallet_transfer_in',
        'wallet_transfer_out', 'wallet_withdrawal', 'admin_adjustment',
        'adjustment', 'migration', 'sync', 'login',
    ];

    public function index(Request $request, string $id): JsonResponse
    {
        $instance = ChildInstance::findOrFail($id);
        $data = $request->validate([
            'period' => 'nullable|in:24h,7d,30d,all',
            'limit' => 'nullable|integer|min:1|max:50',
            'query' => 'nullable|string|max:100',
        ]);

        $period = $data['period'] ?? '30d';
        $limit = (int) ($data['limit'] ?? 20);
        $cutoff = match ($period) {
            '24h' => now()->subDay(),
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            default => null,
        };

        $eligible = fn ($query, string $alias = 'child_transactions') => $query
            ->where("{$alias}.child_instance_id", $instance->id)
            ->whereNotNull("{$alias}.child_customer_id")
            ->whereNotNull("{$alias}.transacted_at")
            ->whereIn(DB::raw("LOWER({$alias}.status)"), self::ACTIVE_STATUSES)
            ->whereNotNull("{$alias}.transaction_type")
            ->whereNotIn("{$alias}.transaction_type", self::EXCLUDED_TYPES)
            ->when($cutoff, fn ($q) => $q->where("{$alias}.transacted_at", '>=', $cutoff));

        // Anti-join selects exactly one latest qualifying transaction for
        // each customer (id breaks equal-timestamp ties) in one DB query.
        $query = ChildTransaction::query()
            ->from('child_transactions as current_tx')
            ->join('child_customers as customer', 'customer.id', '=', 'current_tx.child_customer_id')
            ->select([
                'customer.id', 'customer.external_id', 'customer.username',
                'customer.email', 'customer.phone', 'customer.migrated_to_user_id',
                'current_tx.id as latest_transaction_id',
                'current_tx.transacted_at as latest_transaction_at',
                'current_tx.transaction_type as latest_transaction_type',
                'current_tx.amount as latest_transaction_amount',
                'current_tx.status as latest_transaction_status',
            ]);

        $eligible($query, 'current_tx');
        $query->whereNotExists(function ($newer) use ($eligible) {
            $newer->selectRaw('1')->from('child_transactions as newer_tx')
                ->whereColumn('newer_tx.child_customer_id', 'current_tx.child_customer_id')
                ->where(function ($later) {
                    $later->whereColumn('newer_tx.transacted_at', '>', 'current_tx.transacted_at')
                        ->orWhere(function ($tie) {
                            $tie->whereColumn('newer_tx.transacted_at', 'current_tx.transacted_at')
                                ->whereColumn('newer_tx.id', '>', 'current_tx.id');
                        });
                });
            $eligible($newer, 'newer_tx');
        });

        if (! empty($data['query'])) {
            $term = '%' . addcslashes($data['query'], '%_\\') . '%';
            $query->where(function ($search) use ($term) {
                $search->where('customer.username', 'like', $term)
                    ->orWhere('customer.email', 'like', $term)
                    ->orWhere('customer.phone', 'like', $term);
            });
        }

        $rows = $query->orderByDesc('current_tx.transacted_at')
            ->orderByDesc('current_tx.id')
            ->limit($limit)
            ->get();

        return $this->success([
            'customers' => $rows,
            'period' => $period,
            'limit' => $limit,
            'qualifying_statuses' => self::ACTIVE_STATUSES,
        ]);
    }
}
