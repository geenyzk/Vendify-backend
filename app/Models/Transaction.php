<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\Promotion;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    public const TYPE_AIRTIME_TO_CASH = 'airtime_to_cash';

    //
    protected $appends = [
        'service', 'meter_type', 'meter_number', 'customer_name', 'distribution_company', 'electricity_token',
        'cable_service', 'cable_service_name', 'cable_package_name', 'cable_identifier', 'cable_subscription_type',
    ];
    protected $hidden = ['idempotency_key', 'raw_payload'];
    protected $fillable = [
        'user_id', 'transaction_type', 'provider', 'account_or_phone', 'amount', 'cost',
        'quantity', 'status', 'transaction_reference', 'payment_reference',
        'funding_method', 'balance_before', 'balance_after', 'completed_at',
        'response_message', 'service_fee', 'platform', 'receiver', 'plan_type', 'token',
        'promotion_id', 'discount_amount', 'refunded_at', 'refund_reason',
        'related_reference',
        'airtime_to_cash_request_id',
        'idempotency_key', 'raw_payload',
        'network', 'airtime_plan_id', 'primary_provider_id', 'final_provider_id', 'fallback_used', 'is_sandbox',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
        'refunded_at' => 'datetime',
        'raw_payload' => 'array',
        'fallback_used' => 'boolean',
        'is_sandbox' => 'boolean',
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

    public function airtimeToCashRequest(): BelongsTo
    {
        return $this->belongsTo(AirtimeToCashRequest::class, 'airtime_to_cash_request_id');
    }

    public function scopeReal($query)
    {
        return $query->where('is_sandbox', false);
    }

    public function getServiceAttribute(): ?string
    {
        return $this->transaction_type === 'electric_bill' ? 'electricity' : null;
    }

    public function getMeterTypeAttribute(): ?string
    {
        return $this->transaction_type === 'electric_bill'
            ? ($this->raw_payload['meter_type'] ?? $this->plan_type)
            : null;
    }

    public function getMeterNumberAttribute(): ?string
    {
        if ($this->transaction_type !== 'electric_bill') {
            return null;
        }

        $meter = (string) ($this->raw_payload['meter_number'] ?? $this->account_or_phone ?? $this->receiver ?? '');
        return $meter !== '' ? $meter : null;
    }

    public function getCustomerNameAttribute(): ?string
    {
        return $this->transaction_type === 'electric_bill'
            ? ($this->raw_payload['customer_name'] ?? null)
            : null;
    }

    public function getDistributionCompanyAttribute(): ?string
    {
        if ($this->transaction_type !== 'electric_bill') {
            return null;
        }

        $company = $this->raw_payload['distribution_company'] ?? $this->raw_payload['disco'] ?? null;
        if (is_string($company) && preg_match('/\(([^)]+)\)/', $company, $match)) {
            return trim($match[1]);
        }

        if ((! is_string($company) || trim($company) === '') && $this->provider === 'electricity_sandbox') {
            return 'Ikeja Electric';
        }

        return is_string($company) && trim($company) !== '' ? $company : null;
    }

    public function getElectricityTokenAttribute(): ?string
    {
        return $this->transaction_type === 'electric_bill' && is_string($this->token) && trim($this->token) !== ''
            ? $this->token
            : null;
    }

    /*
     * ── Cable snapshot ──────────────────────────────────────────────────
     * What was bought, as it read at the time of purchase (written by
     * VendorBase::process). These deliberately never look the package up in
     * cable_plans: a receipt has to keep showing "DStv Compact" after the
     * plan is renamed, re-pointed or deleted. A row written before this
     * snapshot existed simply returns null and the UI omits the line.
     */

    private function cableSnapshot(string $key): ?string
    {
        if ($this->transaction_type !== 'cable_subscription') {
            return null;
        }

        // raw_payload is absent whenever a query selected a narrower column
        // set, so never index it directly.
        $payload = is_array($this->raw_payload) ? $this->raw_payload : [];
        $value = $payload[$key] ?? null;

        return is_scalar($value) && trim((string) $value) !== '' ? (string) $value : null;
    }

    public function getCableServiceAttribute(): ?string
    {
        return $this->cableSnapshot('cable_service');
    }

    public function getCableServiceNameAttribute(): ?string
    {
        return $this->cableSnapshot('cable_service_name');
    }

    public function getCablePackageNameAttribute(): ?string
    {
        return $this->cableSnapshot('cable_package_name');
    }

    public function getCableIdentifierAttribute(): ?string
    {
        return $this->cableSnapshot('cable_identifier')
            ?? ($this->transaction_type === 'cable_subscription'
                ? ($this->receiver ?: $this->account_or_phone ?: null)
                : null);
    }

    public function getCableSubscriptionTypeAttribute(): ?string
    {
        // plan_type has carried change/renew since before the snapshot,
        // so older rows still resolve.
        return $this->cableSnapshot('cable_subscription_type')
            ?? ($this->transaction_type === 'cable_subscription'
                ? (in_array(strtolower((string) $this->plan_type), ['change', 'renew'], true)
                    ? strtolower((string) $this->plan_type)
                    : null)
                : null);
    }

    /**
     * What a customer may be told about who served this order.
     *
     * `provider` is a vendor adapter key ("VTU.ng", "cheapdatahub") — an
     * operational detail of how Vendify fulfils an order, not a product a
     * customer bought. Electricity is the one exception: there the meaningful
     * answer is the disco that supplied the meter, which is genuinely part of
     * what was purchased. Everything else resolves to null and the receipt
     * simply omits the line.
     *
     * Staff keep the raw value through the admin surfaces; see
     * TransactionResource.
     */
    public function customerFacingProvider(): ?string
    {
        if ($this->transaction_type !== 'electric_bill') {
            return null;
        }

        $disco = $this->distribution_company;
        if (is_string($disco) && trim($disco) !== '') {
            return $disco;
        }

        return $this->provider === 'electricity_sandbox' ? 'Ikeja Electric' : null;
    }

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

    public function supportTickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class);
    }

    public function whatsappSupportAssignments(): HasMany
    {
        return $this->hasMany(WhatsAppSupportAssignment::class);
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
            ->where('is_sandbox', false)
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
