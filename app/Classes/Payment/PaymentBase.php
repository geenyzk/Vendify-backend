<?php

namespace App\Classes\Payment;

use App\Classes\AdminNotifier;
use App\Classes\Payment\Interface\PaymentInterface;
use App\Models\Bank;
use App\Models\ChildCreditEvent;
use App\Models\ChildVirtualAccount;
use App\Models\Provider;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

abstract class PaymentBase implements PaymentInterface
{
    protected Provider $provider;
    protected string $providerName;

    public function __construct(Provider $provider)
    {
        $this->provider = $provider;
    }

    abstract public function generate(User $user): array|null;

    /**
     * Create a reserved account for an arbitrary customer identity rather than
     * a parent User — the seam the parent uses to generate accounts for an
     * affiliate child's customers. Only providers that support it override
     * this; the default means "not supported".
     *
     * @param array<string, mixed> $customer
     */
    public function generateForCustomer(array $customer): ?array
    {
        return null;
    }

    /**
     * The receiving virtual account number on an incoming funding webhook, if
     * the provider's payload exposes it. Used to map a funding to a child
     * virtual account. Default null — providers override.
     */
    public function virtualAccountNumber(Request $request): ?string
    {
        return null;
    }

    public function generateAccount(User $user): void
    {
        try {
            // This runs on every login and registration. Calling the provider's
            // (remote, often slow) account-creation API for a user who already
            // has a virtual account with this provider was the main reason
            // sign-in/sign-up felt sluggish — so short-circuit on a cheap DB
            // check before ever touching the network.
            $alreadyProvisioned = Bank::where('user_id', $user->id)
                ->where('provider', $this->providerName)
                ->exists();
            if ($alreadyProvisioned) {
                return;
            }

            $response = $this->generate($user);

            if (!$response) {
                Log::warning("No account generated for user {$user->id}.");
                return;
            }

            $existing = Bank::where('user_id', $user->id)
                ->where('bank_name', $response['bank_name'])
                ->first();
            if (!$existing) {
                Bank::create($response);

            }
        } catch (\Throwable $th) {
            // Used to be a bare empty catch — a failure here (bad
            // credentials, provider API down, unexpected response shape)
            // was completely invisible; a customer just silently never got
            // a virtual account with no trace of why.
            Log::error("generateAccount failed for user {$user->id}: " . $th->getMessage(), [
                'provider' => $this->providerName ?? get_class($this),
                'trace' => $th->getTraceAsString(),
            ]);
        }

    }

    abstract protected function getHeaders(): array;
    abstract protected function formatPayload(array|User $payload, ?User $user = null): array;
    abstract protected function formatResponse(array $data, User $user): array;

    /**
     * Provider-specific PARSING ONLY — no side effects. Must NOT touch
     * $user->wallet_balance or save anything; that's centralized in
     * webhook() below so the credit/idempotency logic exists in exactly
     * one place instead of being copy-pasted (and drifting) per provider.
     *
     * Required keys: transaction_reference, status ('success'|'fail'),
     * amount, user_email. Everything else is passed through to the
     * Transaction row as-is.
     */
    abstract protected function callback(Request $request): array;

    /**
     * Verifies the request actually came from the provider (HMAC/hash
     * against a secret configured on this Provider row), not just that
     * the {identifier} in the URL resolved to a real provider — the
     * identifier is a UUID but still effectively public (visible in any
     * provider dashboard's webhook-URL field, logs, etc.), so without
     * this ANY POST to the right URL could forge a "successful payment"
     * and credit an arbitrary user's wallet.
     */
    abstract protected function verifyWebhookSignature(Request $request): bool;

    public function webhook(Request $request): bool
    {
        try {
            if (!$this->verifyWebhookSignature($request)) {
                Log::warning("Webhook signature verification failed for provider [{$this->providerName}].", [
                    'provider_id' => $this->provider->id,
                ]);
                return false;
            }

            $callback = $this->callback($request);
            if (empty($callback) || empty($callback['transaction_reference'])) {
                Log::warning('Missing transaction_reference in callback.', ['callback' => $callback]);
                return true;
            }

            $reference = $callback['transaction_reference'];

            DB::transaction(function () use ($callback, $reference) {
                // Row-lock so two near-simultaneous deliveries of the same
                // webhook (a provider retry racing the original) can't both
                // read "not yet processed" and both credit the wallet.
                $existing = Transaction::where('transaction_reference', $reference)->lockForUpdate()->first();

                if ($existing && $existing->status === 'success') {
                    Log::info('Webhook for already-processed transaction ignored.', ['transaction_reference' => $reference]);
                    return;
                }

                $user = !empty($callback['user_email'])
                    ? User::where('email', $callback['user_email'])->first()
                    : null;

                // transactions.user_id is a NOT NULL column — a Transaction
                // row simply cannot be created without a real user to attach
                // it to. This used to only guard the success case, so a
                // fail-status webhook for an unknown email would fall
                // through to updateOrCreate() below, throw on the NOT NULL
                // constraint, and get silently swallowed by the outer
                // catch — no wallet credit (correct) but also no audit
                // trail and no clear log entry explaining why.
                if (!$user) {
                    Log::error('Webhook for unknown user — no matching account, no transaction row created, no wallet credit.', [
                        'transaction_reference' => $reference,
                        'user_email' => $callback['user_email'] ?? null,
                        'status' => $callback['status'],
                    ]);
                    return;
                }

                if ($callback['status'] === 'success') {
                    $user->wallet_balance += $callback['amount'];
                    $user->save();
                }

                $callback['user_id'] = $user->id;
                unset($callback['user_email']);

                Log::info('Webhook received.', ['transaction_reference' => $reference, 'status' => $callback['status']]);

                Transaction::updateOrCreate(
                    ['transaction_reference' => $reference],
                    $callback
                );
            });

            return true;
        } catch (\Throwable $e) {
            // \Exception alone doesn't catch \Error/\TypeError — e.g. the
            // uninitialized-typed-property bug that used to make Monnify's
            // and PaymentPoint's webhooks fatal-crash on every call,
            // bypassing this catch entirely.
            Log::error('Webhook processing failed.', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return true;
        }
    }


    /**
     * Initiate a bank transfer to a vendor's account.
     * Payment gateways that support outbound transfers must override this.
     *
     * @param  array{account_bank: string, account_number: string, amount: float, narration: string, reference: string}  $payload
     * @return array{status: string, message: string, data: array}
     */
    public function transfer(array $payload): array
    {
        throw new \RuntimeException(class_basename($this) . ' does not support outbound transfers.');
    }

    /**
     * Fetch the list of banks supported by this gateway for outbound transfers.
     * Payment gateways that support bank lookups must override this.
     *
     * @return array<int, array{code: string, name: string}>
     */
    public function getBanks(): array
    {
        throw new \RuntimeException(class_basename($this) . ' does not support fetching banks.');
    }

    /**
     * Whether this gateway has a real transfer()/getBanks() implementation —
     * used to pick the right gateway for wallet-to-bank withdrawals without
     * hardcoding a provider name. See PaymentFactory::makeTransferCapable().
     */
    public function supportsTransfers(): bool
    {
        return false;
    }

    protected function creditedAmount($amount)
    {
        $amount = floatval($amount);

        $v = Provider::whereName($this->providerName)->first(["charge_fee", "charge_fee_cap", "charge_type"]);
        if (!$v) {
            return $amount; // fallback if provider not found
        }

        $fee = 0.0;
        if ($v->charge_type === "percent") {
            $fee = ($amount * floatval($v->charge_fee)) / 100;
        } else if ($v->charge_type === "fiat") {
            $fee = floatval($v->charge_fee);
        }

        if ($v->charge_fee_cap !== null && $v->charge_fee_cap !== '') {
            $fee = min($fee, floatval($v->charge_fee_cap));
        }

        return $amount - $fee;
    }

    /**
     * The withdrawal fee this gateway charges on a payout of $amount — flat
     * ("fiat") or a percentage ("percent") of the amount, per its configured
     * withdrawal_fee/withdrawal_fee_type. 0 when unset.
     */
    public function withdrawalFee(float $amount): float
    {
        $fee = (float) ($this->provider->withdrawal_fee ?? 0);
        if ($fee <= 0) {
            return 0.0;
        }

        $type = $this->provider->withdrawal_fee_type ?? 'fiat';

        return $type === 'percent'
            ? round($amount * $fee / 100, 2)
            : round($fee, 2);
    }

    /**
     * The raw withdrawal-fee config, so callers (the banks endpoint) can hand
     * it to the storefront to preview the fee before a request is submitted.
     */
    public function withdrawalFeeConfig(): array
    {
        return [
            'fee' => (float) ($this->provider->withdrawal_fee ?? 0),
            'type' => $this->provider->withdrawal_fee_type ?? 'fiat',
        ];
    }


}
