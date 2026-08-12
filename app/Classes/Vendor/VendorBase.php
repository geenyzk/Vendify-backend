<?php

namespace App\Classes\Vendor;

use App\Classes\TemplateParser;
use App\Classes\TransactionService;
use App\Classes\Vendor\Interface\VendorInterface;
use App\Exceptions\InsufficientBalanceException;
use App\HttpResponse;
use App\Models\Message;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use App\Support\VendorErrorMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;

abstract class VendorBase implements VendorInterface
{
    use HttpResponse;
    protected string $providerName;
    protected bool $isSandbox = false;

    protected Vendor $provider;

    protected array $networkIDs = [
        'mtn' => 1,
        'airtel' => 2,
        'glo' => 3,
        '9mobile' => 4,
    ];

    protected array $cableNetworkIDs = [
        'gotv' => 1,
        'dstv' => 2,
        'startime' => 3,
    ];

    public function __construct(Vendor $provider)
    {
        $this->provider = $provider;
    }
     public function process(string $service, array $payload):JsonResponse
    {
        $user = Auth::user();

        // Amount the customer actually pays (after discounts, plus any bill
        // service fee) — computed authoritatively in the controller and
        // passed through. This is what we hold.
        $chargeAmount = (float) ($payload['final_amount'] ?? $payload['amount'] ?? 0);

        // ── Reserve BEFORE contacting the vendor ──────────────────────────
        // Value must never be delivered on credit: hold the funds first,
        // refusing outright if the wallet can't cover it. Everything the
        // vendor delivers below is already paid for; the fail paths refund.
        try {
            $reservation = TransactionService::reserve($user, $chargeAmount);
        } catch (InsufficientBalanceException $e) {
            return $this->fail([], $e->getMessage(), 402);
        }

        try {
            $formattedPayload = $this->formatPayload($service, $payload);
             if ($this->isSandbox) {
                // Sandbox delivers nothing — release the hold so a test buy
                // doesn't silently drain the wallet.
                TransactionService::refund($user, $chargeAmount);
                return $this->success([]);
            }
            $parser = TemplateParser::make();
            $response = $this->sendRequest($service, $formattedPayload) ?? [];
            // Merge API response with original payload to preserve discount/promotion data
            $formattedResponse = $this->formatResponse($service, array_merge($response['data'] ?? $response ?? [], $payload));
            // Record the vendor cost of goods for this sale (formatResponse drops
            // the plan ids, so compute it here where $payload is still intact) —
            // profit = amount − cost on the admin dashboard.
            $formattedResponse['cost'] = $this->resolveCost($service, $payload);
            // For data, record the plan's volume in GB as the quantity so the
            // dashboard can report data sold in GB reliably (the vendor reply's
            // own quantity is frequently missing).
            if ($service === 'data') {
                $gb = $this->resolveDataGb($payload);
                if ($gb !== null) {
                    $formattedResponse['quantity'] = $gb;
                }
            }
            $transaction = TransactionService::record($formattedResponse, $user, $reservation);

            // Outright rejection → give the money straight back. A 'pending'
            // stays reserved: the webhook keeps it on success or refunds it
            // on a confirmed failure (see webhook()).
            if (($transaction['status'] ?? null) === 'fail') {
                TransactionService::refund($user, $chargeAmount);
            }

            $message = Message::wherePurpose($service . "_" . $transaction['status'])->first();
            // A custom Message template is optional — when none exists for
            // this purpose, fall straight through to the vendor's own
            // message instead of parsing an empty template (which returns
            // "", not null, so `$parsedMessage ?? ...` used to always "win"
            // with a blank string and hide the real vendor response).
            $responseMessage = $message
                ? $parser->with(["transaction" => $transaction])->parse($message->body ?? "")
                : ($response['data']['msg'] ?? $response['msg'] ?? $response['message'] ?? $response['response_message'] ?? null);

            // Log transaction details
            Log::info("Transaction Completed", [
                'transaction_id' => $transaction['id'] ?? null,
                'amount' => $transaction['amount'] ?? null,
                'discount_applied' => $transaction['discount_applied'] ?? null,
                'status' => $transaction['status'] ?? null
            ]);

            // Return success response with full transaction details
            if ($transaction['status'] === "success") {
                return $this->success($transaction, $responseMessage, 200);
            } elseif ($transaction['status'] === "pending") {
                // Ogdams is the first vendor to ever produce this (its 201
                // "queued" / 202 "processing" codes) — the real outcome
                // arrives later via webhook(). Reporting it as a 500 fail()
                // told the customer their purchase failed when it was
                // actually just still in flight; 202 Accepted + the
                // transaction record lets the frontend show a "processing"
                // state instead of a false failure.
                // A vend is a customer-facing action even when an owner is
                // currently using the storefront. Staff diagnostics remain
                // available in the admin transaction view and logs.
                $publicMessage = VendorErrorMessage::forCurrentUser($responseMessage, 'pending', false);
                $transaction['response_message'] = $publicMessage;
                return $this->success($transaction, $publicMessage, 202);
            } else {
                return $this->fail([], VendorErrorMessage::forCurrentUser($responseMessage, 'fail', false), 500);
            }
        } catch (\Throwable $e) {
            // Vendor call blew up after we reserved (e.g. a null/non-JSON
            // response — \Exception alone wouldn't catch the \TypeError).
            // Release the hold so the customer isn't charged for a purchase
            // that never left the building.
            TransactionService::refund($user, $chargeAmount);
            Log::error("Transaction Error", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this->fail([], VendorErrorMessage::forCurrentUser($e->getMessage(), 'fail', false), 500);
        }
    }

    /**
     * The vendor cost of goods for this sale, or null when it can't be known.
     * Data/cable read the plan's cost price off the providerables pivot;
     * electricity's cost of goods is the token itself ($payload['amount'] is
     * the pre-fee token the disco charges — the platform's margin is the
     * service fee added on top). Everything else (airtime, exam, …) has no
     * tracked cost yet and is left out of the profit figure.
     */
    protected function resolveCost(string $service, array $payload): ?float
    {
        switch ($service) {
            case 'data':
                return $this->planCost(\App\Models\DataPlan::class, $payload['data_plan'] ?? null);
            case 'cable':
                return $this->planCost(\App\Models\CablePlan::class, $payload['cable_plan'] ?? null);
            case 'airtime':
                return $this->airtimeCost($payload);
            case 'electricity':
                $token = (float) ($payload['amount'] ?? 0);
                return $token > 0 ? $token : null;
            default:
                return null;
        }
    }

    /**
     * Airtime has no fixed cost price: it sells at face value and the provider
     * bills us less by an agreed commission, so that discount IS the cost of
     * goods — NGN 1,000 of airtime at a 3.5% discount costs us NGN 965.
     *
     * Returns null when the serving provider has no discount configured, which
     * keeps airtime out of the profit figure exactly as before rather than
     * costing it at zero (which would book the whole sale as profit).
     */
    private function airtimeCost(array $payload): ?float
    {
        $amount = (float) ($payload['amount'] ?? 0);
        $network = $payload['network'] ?? null;
        if ($amount <= 0 || !$network) {
            return null;
        }

        // Same plan resolution as VTUServiceFactory::airtimePlanVendor — a plan
        // with no category is "vtu", and an exact category match wins.
        $category = $payload['network_type'] ?? 'vtu';
        $plans = \App\Models\AirtimePlan::where('name', $network)->where('active', true)->get();
        $plan = $plans->first(fn ($p) => ($p->category ?: 'vtu') === $category) ?? $plans->first();

        $discount = $plan?->discountFor((int) ($this->provider->id ?? 0));
        if ($discount === null) {
            return null;
        }

        return round($amount * (1 - $discount / 100), 2);
    }

    /**
     * The cost of goods for this plan *from the vendor actually serving the
     * sale*. A plan's fallback provider resells it at its own price, so a
     * failed-over sale must be costed against `fallback_cost_price`, not the
     * primary's `cost_price` — otherwise the recorded profit is measured
     * against a provider that was never paid.
     *
     * `fallback_cost_price` is nullable: when it isn't set (legacy rows, or a
     * fallback that genuinely costs the same) we fall back to `cost_price`,
     * which is exactly the old behaviour.
     */
    private function planCost(string $modelClass, $planId): ?float
    {
        if (!$planId) {
            return null;
        }

        $baseQuery = DB::table('providerables')
            ->where('providerable_id', $planId)
            ->where('providerable_type', $modelClass);
        $row = (clone $baseQuery)->where('provider_id', $this->provider->id)->first()
            ?? $baseQuery->first(); // legacy single-row compatibility

        if (!$row) {
            return null;
        }

        $actingId = (int) ($this->provider->id ?? 0);
        // Only treat this as a fallback sale when the fallback is a *different*
        // vendor than the primary; a plan can name the same vendor in both.
        $fallback = $this->fallbackRowForProvider($row, $actingId);
        $servedByFallback = $fallback !== null
            && (int) ($row->provider_id ?? 0) !== $actingId;

        $cost = $servedByFallback
            ? ($fallback['cost_price'] ?? $row->cost_price)
            : ($row->cost_price ?? null);

        return $cost !== null ? (float) $cost : null;
    }

    /**
     * @return array<int, array{provider_id:int, server_id:?string, cost_price:?float, provider_discount:?float}>
     */
    private function fallbackRows(object $row): array
    {
        $fallbacks = property_exists($row, 'fallbacks') ? $row->fallbacks : null;
        $decoded = is_string($fallbacks) && $fallbacks !== ''
            ? json_decode($fallbacks, true)
            : null;

        if (is_array($decoded)) {
            $rows = [];
            foreach ($decoded as $entry) {
                if (! is_array($entry) || empty($entry['provider_id'])) {
                    continue;
                }

                $rows[] = [
                    'provider_id' => (int) $entry['provider_id'],
                    'server_id' => ($entry['server_id'] ?? null) !== null && $entry['server_id'] !== ''
                        ? (string) $entry['server_id']
                        : null,
                    'cost_price' => ($entry['cost_price'] ?? null) !== null && $entry['cost_price'] !== ''
                        ? (float) $entry['cost_price']
                        : null,
                    'provider_discount' => ($entry['provider_discount'] ?? null) !== null && $entry['provider_discount'] !== ''
                        ? (float) $entry['provider_discount']
                        : null,
                ];
            }

            return $rows;
        }

        if (($row->fallback_provider_id ?? null) === null) {
            return [];
        }

        return [[
            'provider_id' => (int) $row->fallback_provider_id,
            'server_id' => ($row->fallback_server_id ?? null) !== null ? (string) $row->fallback_server_id : null,
            'cost_price' => ($row->fallback_cost_price ?? null) !== null ? (float) $row->fallback_cost_price : null,
            'provider_discount' => ($row->fallback_provider_discount ?? null) !== null ? (float) $row->fallback_provider_discount : null,
        ]];
    }

    private function fallbackRowForProvider(object $row, int $providerId): ?array
    {
        if ($providerId <= 0) {
            return null;
        }

        foreach ($this->fallbackRows($row) as $fallback) {
            if ((int) $fallback['provider_id'] === $providerId) {
                return $fallback;
            }
        }

        return null;
    }

    /**
     * The data plan's volume in GB, parsed from its advertised size (e.g.
     * "500MB" → 0.488, "2GB" → 2.0), or null when it can't be determined.
     */
    protected function resolveDataGb(array $payload): ?float
    {
        $planId = $payload['data_plan'] ?? null;
        if (!$planId) {
            return null;
        }

        $plan = \App\Models\DataPlan::find($planId);
        if (!$plan) {
            return null;
        }

        $gb = $this->convertDataPlanToGB((string) $plan->plan);

        return $gb > 0 ? $gb : null;
    }

    /**
     * Resolve the upstream plan identifier for this exact provider. Plans can
     * store different identifiers for their primary and fallback vendors.
     */
    protected function configuredPlanId(Model $plan): ?string
    {
        $baseQuery = DB::table('providerables')
            ->where('providerable_id', $plan->getKey())
            ->where('providerable_type', get_class($plan));
        $row = (clone $baseQuery)->where('provider_id', $this->provider->id)->first()
            ?? $baseQuery->first(); // old plans may carry one shared server_id

        if (!$row) {
            return null;
        }

        $value = null;
        if ((int) ($row->provider_id ?? 0) === (int) $this->provider->id) {
            $value = $row->server_id ?? null;
        } else {
            $fallback = $this->fallbackRowForProvider($row, (int) $this->provider->id);
            $value = $fallback['server_id'] ?? null;
        }

        return $value !== null && (string) $value !== '' && (string) $value !== '0'
            ? (string) $value
            : null;
    }

    abstract public function sendRequest(string $service, array $payload): array;


    abstract public function checkBalance(): string;

    abstract public function verifyTransaction(string $tx_ref): array;

    abstract protected function getAuthHeaders(): array;
    abstract public function verifyUser(string $service, string $identifier, array $payload): JsonResponse;
    abstract protected function formatResponse(string $service,array $payload): array;

    public function supportsService(string $service): bool
    {
        return in_array($service, $this->getSupportedServices());
    }

    public function providerId(): int
    {
        return (int) $this->provider->id;
    }

    public function sandbox(): static
    {
        $this->isSandbox = true;
        return $this;
    }

    public function isHealthy(): bool
    {
        try {
            $response = $this->login();
            return $response['status'] === 'success';

        } catch (\Throwable $e) {
            Log::warning("Vendor [{$this->providerName}] is unhealthy.");
            return false;
        }
    }

    function plans(?array $payload=null): mixed
    {

        return $this->getPlans($payload);
    }

    /**
     * Parse a vendor-supplied balance/amount that may arrive as a
     * comma-grouped string ("4,495.00"), a plain numeric string, or a
     * number. A bare (float) cast stops at the first non-numeric character —
     * so `(float) "4,495"` is 4.0, not 4495 — which is why provider balances
     * were showing up truncated. Strip grouping separators, currency symbols
     * and whitespace first, then cast.
     */
    protected function normalizeAmount($value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        $clean = preg_replace('/[^0-9.\-]/', '', (string) $value);

        return is_numeric($clean) ? (float) $clean : 0.0;
    }

    abstract protected function getSupportedServices(): array;
    abstract protected function getPlans(?array $payload=null): array|JsonResponse;
    abstract protected function callback(Request $request):array;

    abstract protected function pingEndpoint(): string;
    abstract protected function endpoint(string $service): string;

    protected function convertDataPlanToGB(string $dataplan): float {
    $dataplan = strtoupper(trim($dataplan));

    // Match value and unit using regex (e.g., "500MB", "1.5GB")
    if (preg_match('/([\d\.]+)\s*(MB|GB)/', $dataplan, $matches)) {
        $value = (float) $matches[1];
        $unit = $matches[2];

        if ($unit === 'MB') {
            return round($value / 1024, 3); // Convert MB to GB
        }

        if ($unit === 'GB') {
            return round($value, 3);
        }
    }

    return 0.0; // fallback if parsing fails
}

    public function webhook(Request $request):void{
        $this->settleCallback($this->callback($request));
    }

    /**
     * Settle a pending transaction from a provider-shaped callback array
     * (['status' => success|fail, 'tx_ref' => ..., ...]). Split out of
     * webhook() so non-HTTP completion paths — the SIM-vend job ack and
     * expiry sweeper — reuse the exact same locking, refund and reward
     * semantics without fabricating a Request.
     */
    protected function resolveCallbackReference(array $callback): ?string
    {
        foreach (['tx_ref', 'transaction_reference', 'request_id', 'request-id', 'customer_reference', 'reference', 'payment_reference'] as $key) {
            if (array_key_exists($key, $callback) && $callback[$key] !== null && $callback[$key] !== '') {
                return (string) $callback[$key];
            }
        }

        return null;
    }

    public function settleCallback(array $callback): void
    {
        $ref = $this->resolveCallbackReference($callback);

        if (!$ref) {
            Log::warning("Vendor webhook: callback carried no transaction reference", [
                'provider' => $this->providerName ?? null,
                'callback_keys' => array_keys($callback),
            ]);
            return;
        }

        DB::transaction(function () use ($callback, $ref) {
            $transaction = Transaction::where("transaction_reference", $ref)
                ->lockForUpdate()
                ->first();

            if (!$transaction && !empty($callback['payment_reference'])) {
                $transaction = Transaction::where("payment_reference", $callback['payment_reference'])
                    ->lockForUpdate()
                    ->first();
            }

            if (!$transaction) {
                Log::warning("Vendor webhook: no transaction for reference", ['reference' => $ref]);
                return;
            }

            $previousStatus = $transaction->status;
            $newStatus = $callback['status'] ?? $previousStatus;

            if ($previousStatus === 'pending' && $newStatus !== 'pending') {
                $user = User::whereKey($transaction->user_id)->lockForUpdate()->first();

                if ($newStatus === 'fail' && $user) {
                    $user->increment('wallet_balance', (float) $transaction->amount);
                    $callback['balance_after'] = (float) $user->fresh()->wallet_balance;
                } elseif ($newStatus === 'success' && $user) {
                    TransactionService::awardForSettledTransaction(
                        $user,
                        (float) $transaction->amount,
                        $transaction->transaction_type,
                    );
                    $callback['completed_at'] = now();
                }
            }

            $updateData = array_filter(
                array_intersect_key(
                    $callback,
                    array_flip(['status', 'response_message', 'completed_at', 'balance_after', 'payment_reference']),
                ),
                fn ($value) => $value !== null,
            );

            if (!empty($updateData)) {
                $transaction->update($updateData);
            }
        });
    }
}
