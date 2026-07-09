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
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;

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
                return $this->success($transaction, $responseMessage, 202);
            } else {
                return $this->fail([], $responseMessage, 500);
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
            return $this->fail([], $e->getMessage(), 500);
        }
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
        $callback = $this->callback($request);
        $ref = $callback['tx_ref'] ?? null;

        if (!$ref) {
            Log::warning("Vendor webhook: callback carried no tx_ref", [
                'provider' => $this->providerName ?? null,
            ]);
            return;
        }

        DB::transaction(function () use ($callback, $ref) {
            $transaction = Transaction::where("transaction_reference", $ref)
                ->lockForUpdate()
                ->first();

            if (!$transaction) {
                Log::warning("Vendor webhook: no transaction for reference", ['tx_ref' => $ref]);
                return;
            }

            $previousStatus = $transaction->status;
            $newStatus = $callback['status'] ?? $previousStatus;

            // A pending purchase already had its funds reserved (debited) at
            // purchase time. Only the transition OUT of pending needs money
            // handling, and the guard on the DB status makes a duplicate
            // webhook a no-op (the row is already terminal on the second hit).
            if ($previousStatus === 'pending' && $newStatus !== 'pending') {
                $user = User::whereKey($transaction->user_id)->lockForUpdate()->first();

                if ($newStatus === 'fail' && $user) {
                    // Confirmed failure → return the reserved funds.
                    $user->increment('wallet_balance', (float) $transaction->amount);
                    $callback['balance_after'] = (float) $user->fresh()->wallet_balance;
                } elseif ($newStatus === 'success' && $user) {
                    // Funds already held; just pay out the rewards that an
                    // immediate success would have earned at record() time.
                    TransactionService::awardForSettledTransaction(
                        $user,
                        (float) $transaction->amount,
                        $transaction->transaction_type,
                    );
                    $callback['completed_at'] = now();
                }
            }

            $transaction->update($callback);
        });
    }
}
