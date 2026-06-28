<?php

namespace App\Classes\Payment;

use App\Classes\Payment\Interface\PaymentInterface;
use App\Models\Bank;
use App\Models\Provider;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
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

    public function generateAccount(User $user): void
    {
        try {
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
            //throw $th;
        }

    }

    abstract protected function getHeaders(): array;
    abstract protected function formatPayload(array|User $payload, ?User $user = null): array;
    abstract protected function formatResponse(array $data, User $user): array;
    abstract protected function callback(Request $request): array;

    public function webhook(Request $request): void
    {
        try {
            $callback = $this->callback($request);

            if (!isset($callback['transaction_reference'])) {
                Log::warning('Missing transaction_reference in callback.', ['callback' => $callback]);
                return;
            }

            Log::info('Webhook received.', ['transaction_reference' => $callback['transaction_reference']]);

            Transaction::updateOrCreate(
                ['transaction_reference' => $callback['transaction_reference']],
                $callback
            );

        } catch (\Exception $e) {
            Log::error('Webhook processing failed.', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }


    protected function creditedAmount($amount)
    {
        $amount = floatval($amount);

        $v = Provider::whereName($this->providerName)->first(["charge_fee", "charge_type"]);
        if (!$v) {
            return $amount; // fallback if provider not found
        }

        if ($v->charge_type === "percent") {
            $amount -= ((floatval($v->charge_fee) / 100) * $amount);
        } else if ($v->charge_type === "fiat") {
            $amount -= floatval($v->charge_fee);
        }

        return $amount;
    }


}
