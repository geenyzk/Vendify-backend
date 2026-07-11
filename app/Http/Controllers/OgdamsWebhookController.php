<?php

namespace App\Http\Controllers;

use App\Classes\TransactionService;
use App\Jobs\SendTransactionCallback;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\AppNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class OgdamsWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        try {
            $payload = $this->payloadFrom($request);

            if ($payload === null) {
                Log::warning('Ogdams webhook invalid payload', [
                    'reason' => 'empty_or_invalid_json',
                ]);

                return response()->json([
                    'status' => false,
                    'message' => 'Invalid webhook payload',
                ], 422);
            }

            $validator = Validator::make($payload, [
                'status' => ['required', 'boolean'],
                'code' => ['required', 'integer'],
                'event.type' => ['required', 'string'],
                'event.data.reference' => ['required', 'string'],
                'event.data.msg' => ['required', 'string'],
            ]);

            if ($validator->fails()) {
                Log::warning('Ogdams webhook invalid payload', [
                    'reason' => 'validation_failed',
                    'errors' => $validator->errors()->keys(),
                ]);

                return response()->json([
                    'status' => false,
                    'message' => 'Invalid webhook payload',
                ], 422);
            }

            $eventType = (string) data_get($payload, 'event.type');
            $reference = (string) data_get($payload, 'event.data.reference');
            $message = (string) data_get($payload, 'event.data.msg');
            $code = (int) data_get($payload, 'code');
            $callbackStatus = (bool) data_get($payload, 'status');

            Log::info('Ogdams webhook received', [
                'reference' => $reference,
                'callback_status' => $callbackStatus,
                'code' => $code,
                'event_type' => $eventType,
            ]);

            if ($eventType !== 'data') {
                Log::info('Ogdams webhook ignored unsupported event type', [
                    'reference' => $reference,
                    'event_type' => $eventType,
                ]);

                return $this->acknowledge();
            }

            $targetStatus = $this->targetStatus($callbackStatus, $code);
            if ($targetStatus === 'pending') {
                Log::info('Ogdams webhook ignored non-terminal callback', [
                    'reference' => $reference,
                    'code' => $code,
                ]);

                return $this->acknowledge();
            }

            $settled = DB::transaction(function () use ($reference, $targetStatus, $message) {
                $transaction = Transaction::where('transaction_reference', $reference)
                    ->lockForUpdate()
                    ->first();

                if (!$transaction) {
                    Log::warning('Ogdams webhook unknown reference', [
                        'reference' => $reference,
                    ]);

                    return null;
                }

                Log::info('Ogdams webhook transaction found', [
                    'reference' => $reference,
                    'transaction_id' => $transaction->id,
                    'current_status' => $transaction->status,
                ]);

                if ($transaction->provider !== 'ogdams' || $transaction->transaction_type !== 'data_subscription') {
                    Log::warning('Ogdams webhook reference matched non-Ogdams data transaction', [
                        'reference' => $reference,
                        'transaction_id' => $transaction->id,
                        'provider' => $transaction->provider,
                        'transaction_type' => $transaction->transaction_type,
                    ]);

                    return null;
                }

                if ($transaction->status !== 'pending') {
                    Log::info('Ogdams webhook duplicate callback ignored', [
                        'reference' => $reference,
                        'transaction_id' => $transaction->id,
                        'current_status' => $transaction->status,
                        'refunded_at' => optional($transaction->refunded_at)->toDateTimeString(),
                    ]);

                    return null;
                }

                $user = User::whereKey($transaction->user_id)->lockForUpdate()->first();

                if ($targetStatus === 'success') {
                    $transaction->status = 'success';
                    $transaction->response_message = $message;
                    $transaction->completed_at = now();
                    $transaction->save();

                    if ($user) {
                        TransactionService::awardForSettledTransaction(
                            $user,
                            (float) $transaction->amount,
                            $transaction->transaction_type,
                        );
                    }

                    Log::info('Ogdams webhook transaction updated', [
                        'reference' => $reference,
                        'transaction_id' => $transaction->id,
                        'new_status' => 'success',
                    ]);

                    return ['status' => 'success', 'transaction_id' => $transaction->id];
                }

                $transaction->status = 'fail';
                $transaction->response_message = $message;

                if (
                    $user
                    && !$transaction->refunded_at
                    && in_array($transaction->transaction_type, Transaction::REFUNDABLE_TYPES, true)
                    && (float) $transaction->amount > 0
                ) {
                    TransactionService::fundUser(
                        $user,
                        (float) $transaction->amount,
                        'credit',
                        "Ogdams refund for {$transaction->transaction_reference}: {$message}",
                        'manual_funding',
                        'ogdams',
                        $transaction->receiver,
                        $transaction->transaction_reference,
                    );

                    $transaction->refunded_at = now();
                    $transaction->refund_reason = $message;
                    $transaction->balance_after = (float) $user->fresh()->wallet_balance;

                    Log::info('Ogdams webhook refund processed', [
                        'reference' => $reference,
                        'transaction_id' => $transaction->id,
                        'amount' => (float) $transaction->amount,
                    ]);
                }

                $transaction->save();

                Log::info('Ogdams webhook transaction updated', [
                    'reference' => $reference,
                    'transaction_id' => $transaction->id,
                    'new_status' => 'fail',
                ]);

                return ['status' => 'fail', 'transaction_id' => $transaction->id];
            });

            $this->notifySettlement($settled);

            return $this->acknowledge();
        } catch (\Throwable $e) {
            Log::error('Ogdams webhook unexpected exception', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Webhook processing failed',
            ], 500);
        }
    }

    private function payloadFrom(Request $request): ?array
    {
        $content = trim((string) $request->getContent());

        if ($content !== '') {
            $decoded = json_decode($content, true);

            return json_last_error() === JSON_ERROR_NONE && is_array($decoded)
                ? $decoded
                : null;
        }

        $input = $request->all();

        return is_array($input) && $input !== [] ? $input : null;
    }

    private function targetStatus(bool $callbackStatus, int $code): string
    {
        if ($callbackStatus && $code === 200) {
            return 'success';
        }

        if (!$callbackStatus || $code >= 400) {
            return 'fail';
        }

        return 'pending';
    }

    private function notifySettlement(?array $settled): void
    {
        if (!$settled) {
            return;
        }

        $transaction = Transaction::with('user')->find($settled['transaction_id']);
        if (!$transaction || !$transaction->user) {
            return;
        }

        $label = ucwords(str_replace('_', ' ', $transaction->transaction_type));
        $transaction->user->notify(new AppNotification(
            $settled['status'] === 'success' ? 'transaction_success' : 'transaction_failed',
            $settled['status'] === 'success' ? "{$label} successful" : "{$label} failed",
            $settled['status'] === 'success'
                ? "Your {$label} purchase of NGN {$transaction->amount} was successful. Ref: {$transaction->transaction_reference}"
                : "Your {$label} purchase of NGN {$transaction->amount} could not be completed.",
        ));

        SendTransactionCallback::dispatch($transaction->user, $transaction);
    }

    private function acknowledge(): JsonResponse
    {
        return response()->json([
            'status' => true,
            'message' => 'Webhook received',
        ]);
    }
}
