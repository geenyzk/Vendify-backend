<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\Promotion;

class Transaction extends Model
{
    //
    protected $fillable = [
        'user_id', 'transaction_type', 'provider', 'account_or_phone', 'amount', 'cost',
        'quantity', 'status', 'transaction_reference', 'payment_reference',
        'funding_method', 'balance_before', 'balance_after', 'completed_at',
        'response_message', 'service_fee', 'platform', 'receiver', 'plan_type', 'token',
        'promotion_id', 'discount_amount', 'refunded_at', 'refund_reason',
        'related_reference',
        'idempotency_key', 'raw_payload',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
        'refunded_at' => 'datetime',
        'raw_payload' => 'array',
    ];

    // Transaction types where the wallet was actually charged, and a
    // refund can therefore credit money back. Funding types move money
    // in, not out, so "refunding" one makes no sense here.
    // wallet_withdrawal is included (a single-user debit, same shape as the
    // rest) — wallet_transfer_out/_in are deliberately excluded, since
    // crediting one side back here wouldn't reverse the other side of the
    // pair and would create/destroy money.
    public const REFUNDABLE_TYPES = [
        'airtime_recharge', 'data_subscription', 'cable_subscription', 'electric_bill',
        'exam', 'betting_funding', 'airtime_pin', 'data_pin', 'bulksms', 'wallet_withdrawal',
    ];

    /**
     * Get the promotion associated with this transaction.
     */
    public function promotion()
    {
        return $this->belongsTo(Promotion::class, 'promotion_id');
    }

    /**
     * Get the user who owns this transaction.
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public static function generateTransactionId(): string
    {
        return strtoupper('TXN-' . now()->format('YmdHis') . '-' . Str::random(6));
    }

    public static function calculateSummary(Carbon $startDate, Carbon $endDate, ?int $userId = null): array
    {
        $allTypes = [
            'airtime_recharge', 'data_subscription', 'cable_subscription', 'electric_bill', 'exam',
            'betting_funding', 'airtime_pin', 'data_pin', 'bulksms', 'funding'
        ];

        $dataTypes = ['data_subscription', 'data_pin'];

        $summaryMap = [];
        foreach ($allTypes as $type) {
            $summaryMap[$type] = [];
        }

        // Build the base query
        $query = self::whereBetween('created_at', [$startDate, $endDate])
            ->where('status', 'success');

        // Filter by user if provided
        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        $transactions = $query->get();

        foreach ($transactions as $tx) {
            $effectiveType = ($tx->transaction_type === 'wallet_funding' || $tx->transaction_type === 'manual_funding')
                ? ($tx->plan_type === 'credit' ? 'funding' : null)
                : $tx->transaction_type;

            if (!$effectiveType || !in_array($effectiveType, $allTypes)) {
                continue;
            }

            $provider = $tx->provider ?? $tx->plan_type ?? 'default';

            $existingIndex = collect($summaryMap[$effectiveType])
                ->search(fn($item) => $item['provider'] === $provider);

            $quantity = in_array($effectiveType, $dataTypes) ? floatval($tx->quantity) : 0;

            if ($existingIndex === false) {
                $summaryMap[$effectiveType][] = [
                    'transaction_type'    => $effectiveType,
                    'provider'            => $provider,
                    'total_transactions'  => 1,
                    'total_amount'        => floatval($tx->amount),
                    'total_service_fee'   => floatval($tx->service_fee),
                    'total_discount'      => floatval($tx->discount_amount),
                    'total_quantity'      => $quantity,
                ];
            } else {
                $summaryMap[$effectiveType][$existingIndex]['total_transactions'] += 1;
                $summaryMap[$effectiveType][$existingIndex]['total_amount'] += floatval($tx->amount);
                $summaryMap[$effectiveType][$existingIndex]['total_service_fee'] += floatval($tx->service_fee);
                $summaryMap[$effectiveType][$existingIndex]['total_discount'] += floatval($tx->discount_amount);
                $summaryMap[$effectiveType][$existingIndex]['total_quantity'] += $quantity;
            }
        }

        return $summaryMap;
    }



}
