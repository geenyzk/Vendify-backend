<?php

namespace App\Http\Controllers;

use App\Classes\TransactionService;
use App\Models\BettingProvider;
use App\Models\BettingSetting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Betting\BettingProviderManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class BettingController extends Controller
{
    public function index(): JsonResponse
    {
        if (! BettingSetting::current()->enabled) {
            return $this->success(['enabled' => false, 'providers' => []]);
        }

        return $this->success([
            'enabled' => true,
            'providers' => BettingProvider::where('active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'slug', 'verification_supported', 'minimum_amount', 'maximum_amount', 'flat_fee', 'percentage_fee']),
        ]);
    }

    public function verify(Request $request, BettingProviderManager $manager): JsonResponse
    {
        $validated = $request->validate([
            'provider' => ['required', 'string', Rule::exists('betting_providers', 'slug')->where('active', true)],
            'customer_id' => ['required', 'string', 'min:3', 'max:100'],
        ]);

        if (! BettingSetting::current()->enabled) {
            return $this->fail([], 'Betting funding is currently unavailable.', 503);
        }

        $provider = BettingProvider::where('slug', $validated['provider'])->where('active', true)->firstOrFail();
        if (! $provider->verification_supported) {
            return $this->success(['verified' => null, 'verification_supported' => false]);
        }

        try {
            $result = $manager->resolve()->verifyCustomer($provider, $validated['customer_id']);
        } catch (\Throwable $e) {
            Log::error('Betting account verification setup failure', ['error' => $e->getMessage()]);
            return $this->fail([], 'Betting account verification is unavailable right now.', 503);
        }

        if ($result['status'] !== 'success') {
            Log::warning('Betting account verification failed', [
                'provider_id' => $provider->id,
                'internal_status' => $result['internal_status'],
                'provider_response' => $result['raw'],
            ]);

            return $this->fail([], $this->publicMessage($result['internal_status'], true), 422, $result['internal_status']);
        }

        return $this->success([
            'verified' => true,
            'verification_supported' => true,
            'customer_name' => $result['customer_name'],
        ], 'Betting account verified.');
    }

    public function fund(Request $request, BettingProviderManager $manager): JsonResponse
    {
        $validated = $request->validate([
            'provider' => ['required', 'string', Rule::exists('betting_providers', 'slug')->where('active', true)],
            'customer_id' => ['required', 'string', 'min:3', 'max:100'],
            'amount' => ['required', 'numeric'],
            'pin' => ['required', 'string'],
            'idempotency_key' => ['required', 'string', 'min:16', 'max:100'],
        ]);

        if ($pinFailure = $this->transactionPinFailure($request)) {
            return $pinFailure;
        }
        if (! BettingSetting::current()->enabled) {
            return $this->fail([], 'Betting funding is currently unavailable.', 503);
        }

        $user = $request->user();
        $existing = Transaction::where('user_id', $user->id)
            ->where('idempotency_key', $validated['idempotency_key'])
            ->first();
        if ($existing) {
            return $this->transactionResponse($existing, true);
        }

        $provider = BettingProvider::where('slug', $validated['provider'])->where('active', true)->firstOrFail();
        $amount = round((float) $validated['amount'], 2);
        if ($amount < (float) $provider->minimum_amount || $amount > (float) $provider->maximum_amount) {
            return $this->fail([], 'Enter an amount within the allowed range for this betting provider.', 422);
        }

        try {
            $gateway = $manager->resolve();
            if ($provider->verification_supported) {
                $verification = $gateway->verifyCustomer($provider, $validated['customer_id']);
                if ($verification['status'] !== 'success') {
                    Log::warning('Betting funding blocked by account verification', [
                        'provider_id' => $provider->id,
                        'internal_status' => $verification['internal_status'],
                        'provider_response' => $verification['raw'],
                    ]);
                    return $this->fail([], $this->publicMessage($verification['internal_status'], true), 422, $verification['internal_status']);
                }
            }
        } catch (\Throwable $e) {
            Log::error('Betting gateway resolution failed', ['error' => $e->getMessage()]);
            return $this->fail([], 'Betting funding is currently unavailable.', 503, 'provider_unavailable');
        }

        $fee = $provider->chargeFor($amount);
        $charge = round($amount + $fee, 2);
        $reference = Transaction::generateTransactionId();

        try {
            $transaction = DB::transaction(function () use ($user, $provider, $validated, $amount, $fee, $charge, $reference) {
                // Serialize all purchases for this wallet first. This also
                // makes the idempotency lookup deterministic for two clicks
                // arriving at the same instant.
                $lockedUser = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                $duplicate = Transaction::where('user_id', $user->id)
                    ->where('idempotency_key', $validated['idempotency_key'])
                    ->first();
                if ($duplicate) {
                    return $duplicate;
                }

                $before = (float) $lockedUser->wallet_balance;
                if ($before < $charge) {
                    throw new \App\Exceptions\InsufficientBalanceException('Insufficient wallet balance.');
                }
                $lockedUser->decrement('wallet_balance', $charge);

                return Transaction::create([
                    'user_id' => $lockedUser->id,
                    'transaction_type' => 'betting_funding',
                    'provider' => $provider->name,
                    'account_or_phone' => $validated['customer_id'],
                    'amount' => $charge,
                    'cost' => $amount,
                    'service_fee' => $fee,
                    'status' => 'pending',
                    'transaction_reference' => $reference,
                    'idempotency_key' => $validated['idempotency_key'],
                    'balance_before' => $before,
                    'balance_after' => $before - $charge,
                    'receiver' => $validated['customer_id'],
                    'plan_type' => $provider->provider_code ?: $provider->slug,
                    'platform' => \App\Support\TransactionPlatform::current(),
                    'response_message' => 'Betting funding is processing.',
                    'raw_payload' => [
                        'internal_status' => 'pending',
                        'betting_provider_id' => $provider->id,
                        'biller_id' => $provider->biller_id,
                        'funding_amount' => $amount,
                    ],
                ]);
            });
        } catch (\App\Exceptions\InsufficientBalanceException) {
            return $this->fail([], 'Insufficient wallet balance.', 402, 'insufficient_wallet');
        }

        // A concurrent duplicate that won the unique key race must not vend.
        if ($transaction->transaction_reference !== $reference) {
            return $this->transactionResponse($transaction, true);
        }

        try {
            $result = $gateway->fundAccount($provider, $validated['customer_id'], $amount, $reference);
        } catch (\Throwable $e) {
            // The request may have crossed the network boundary. Preserve the
            // debit as pending instead of risking a refund plus a delivered
            // bet-wallet credit; admins can requery by the Vendify reference.
            Log::error('Betting funding request raised an exception', [
                'transaction_reference' => $reference,
                'exception' => get_class($e),
                'error' => $e->getMessage(),
            ]);
            $result = [
                'status' => 'pending',
                'internal_status' => 'provider_unavailable',
                'message' => 'Provider response unavailable.',
                'provider_reference' => null,
                'cost' => null,
                'raw' => ['exception' => class_basename($e)],
            ];
        }

        DB::transaction(function () use ($transaction, $user, $charge, $result) {
            $locked = Transaction::whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') {
                return;
            }

            $status = match ($result['status']) {
                'success' => 'success',
                'pending' => 'pending',
                default => 'fail',
            };
            $locked->update([
                'status' => $status,
                'payment_reference' => $result['provider_reference'],
                'cost' => $result['cost'] ?? $locked->cost,
                'completed_at' => $status === 'pending' ? null : now(),
                'response_message' => $this->publicMessage($status === 'pending' ? 'pending' : $result['internal_status']),
                'raw_payload' => array_merge($locked->raw_payload ?? [], [
                    'internal_status' => $result['internal_status'],
                    'provider_response' => $result['raw'],
                ]),
            ]);

            if ($status === 'fail') {
                TransactionService::refund($user, $charge);
                $locked->update(['balance_after' => $locked->balance_before, 'refunded_at' => now(), 'refund_reason' => $result['internal_status']]);
            } elseif ($status === 'success') {
                TransactionService::awardForSettledTransaction($user, $charge, 'betting_funding');
            }
        });

        Log::info('Betting funding provider result', [
            'transaction_reference' => $reference,
            'provider_id' => $provider->id,
            'internal_status' => $result['internal_status'],
            'provider_response' => $result['raw'],
        ]);

        return $this->transactionResponse($transaction->fresh());
    }

    private function transactionResponse(Transaction $transaction, bool $replayed = false): JsonResponse
    {
        $status = $transaction->status;
        $code = $status === 'pending' ? 202 : ($status === 'success' ? 200 : 422);
        $data = $transaction->only(['id', 'transaction_reference', 'payment_reference', 'amount', 'service_fee', 'status', 'receiver', 'response_message']);
        $data['replayed'] = $replayed;

        return $status === 'fail'
            ? $this->fail(['transaction' => $data], $transaction->response_message, $code, data_get($transaction->raw_payload, 'internal_status', 'failed'))
            : $this->success($data, $transaction->response_message, $code, $status);
    }

    private function publicMessage(string $status, bool $verification = false): string
    {
        return match ($status) {
            'success' => $verification ? 'Betting account verified.' : 'Betting account funded successfully.',
            'pending' => 'Your betting funding is being confirmed. Check your transaction history for updates.',
            'provider_unavailable' => 'Betting funding is currently unavailable. Please try again later.',
            'customer_not_found' => 'We could not verify that betting account. Check the details and try again.',
            'unsupported_biller' => 'This betting provider is not currently supported.',
            'provider_permission_denied' => 'VTU.ng has not authorised betting funding for this provider. Please choose another provider.',
            default => 'We could not complete the betting funding. Please try again later.',
        };
    }
}
