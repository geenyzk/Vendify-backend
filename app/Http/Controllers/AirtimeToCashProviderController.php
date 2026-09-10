<?php

namespace App\Http\Controllers;

use App\Models\AirtimeToCashRequest;
use App\Services\AirtimeToCash\AirtimeToCashProviderConfiguration;
use App\Services\AirtimeToCash\AirtimeToCashProviderManager;
use App\Services\AirtimeToCash\AirtimeToCashProviderService;
use App\Services\AirtimeToCash\AirtimeToCashReconciliationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class AirtimeToCashProviderController extends Controller
{
    public function __construct(
        private AirtimeToCashProviderService $flow,
        private AirtimeToCashProviderManager $manager,
        private AirtimeToCashProviderConfiguration $configuration,
    ) {}

    public function options(Request $request)
    {
        $input = $request->validate(['network_id' => 'sometimes|integer|min:1']);
        $policy = app(\App\Services\AirtimeToCashAvailabilityService::class);
        $networks = \App\Models\Network::query()
            ->when(isset($input['network_id']), fn ($query) => $query->whereKey($input['network_id']))->get();
        $manual = $networks->contains(fn ($network) => $policy->inspect($network)['available']);
        $automated = $networks->contains(function ($network) use ($policy) {
            if (! $policy->inspect($network, null, 'provider')['available']) {
                return false;
            }
            try {
                return $this->manager->availableForNetwork($policy::canonicalName($network->name),
                    (float) $network->airtime_to_cash_min, (float) $network->airtime_to_cash_max);
            } catch (\DomainException) {
                return false;
            }
        });

        // Explicit allowlist: never serialize administrative configuration to customers.
        return $this->success(['provider_available' => $automated,
            'automated_available' => $automated, 'manual_available' => $manual])
            ->header('Cache-Control', 'private, no-store');
    }

    public function quote(Request $request)
    {
        $input = $request->validate(['network_id' => 'required|integer|min:1', 'amount' => 'required|integer|min:1']);
        try {
            return $this->success($this->flow->quote($input['network_id'], $input['amount']));
        } catch (\DomainException $e) {
            return $this->fail([], $e->getMessage(), 422);
        }
    }

    public function start(Request $request)
    {
        $input = $request->validate(['network_id' => 'required|integer|min:1', 'amount' => 'required|integer|min:1',
            'quoted_payout' => 'required|numeric|gt:0', 'sender_phone' => ['required', 'regex:/^0[789][0-9]{9}$/'],
            'idempotency_key' => 'required|uuid']);
        try {
            $atc = $this->flow->start((string) $request->user()->id, $input['network_id'], $input['amount'], $input['quoted_payout'], $input['sender_phone'], $input['idempotency_key']);

            return $this->success($this->customerView($atc));
        } catch (\DomainException $e) {
            return $this->fail([], $e->getMessage(), 422);
        }
    }

    public function verify(Request $request, int $id)
    {
        $otp = $request->attributes->get('atc_secrets')?->take('otp') ?? '';
        try {
            Validator::make(['otp' => $otp], ['otp' => ['required', 'regex:/^[0-9]{6}$/']])->validate();
            try {
                return $this->success($this->customerView($this->flow->verify($id, (string) $request->user()->id, $otp)));
            } catch (\DomainException $e) {
                return $this->fail([], $e->getMessage(), 422);
            }
        } finally {
            unset($otp);
        }
    }

    public function convert(Request $request, int $id)
    {
        $pin = $request->attributes->get('atc_secrets')?->take('pin') ?? '';
        try {
            Validator::make(['pin' => $pin], ['pin' => ['required', 'regex:/^[0-9]{4}$/']])->validate();
            try {
                return $this->success($this->customerView($this->flow->convert($id, (string) $request->user()->id, $pin)));
            } catch (\DomainException $e) {
                return $this->fail([], $e->getMessage(), 422);
            }
        } finally {
            unset($pin);
        }
    }

    public function restart(Request $request, int $id)
    {
        try {
            return $this->success($this->customerView($this->flow->restartOtp($id, (string) $request->user()->id)));
        } catch (\DomainException $e) {
            return $this->fail([], $e->getMessage(), 422);
        }
    }

    public function active(Request $request)
    {
        return $this->success($this->flow->active((string) $request->user()->id)
            ->map(fn ($atc) => $this->customerView($atc))->all());
    }

    public function resume(Request $request, int $id)
    {
        try {
            return $this->success($this->customerView($this->flow->resume($id, (string) $request->user()->id)->setAttribute('resumed', true)));
        } catch (\DomainException $e) {
            return $this->fail([], $e->getMessage(), 422);
        }
    }

    public function status(Request $request, int $id)
    {
        $atc = AirtimeToCashRequest::where('user_id', $request->user()->id)->where('processing_mode', 'provider')->findOrFail($id);

        // Local-only read: no hidden polling or telecom request.
        return $this->success($this->customerView($atc));
    }

    public function customerView(AirtimeToCashRequest $atc): array
    {
        $state = $atc->lifecycle['state'];
        $message = match ($atc->status === 'failed' ? 'failed' : $state) {
            'created' => 'Preparing SIM verification. No transfer has been submitted.',
            'verifying_otp' => 'Checking your verification code. No transfer has been submitted.',
            'setup_review' => 'SIM setup could not be confirmed. No transfer has been submitted. Contact support or start again after verification expires.',
            'awaiting_otp' => $atc->provider_message === 'failed' ? 'Verification failed. Check the code and try again.' : 'Enter the code sent to your SIM.',
            'ready_to_transfer' => match ($atc->provider_message) {
                'invalid_pin' => 'The transfer PIN was rejected. Check the PIN and try again. Your wallet has not been credited.',
                'low_balance' => 'Your SIM does not have enough airtime for this conversion. Recharge it, then try again.',
                default => 'SIM verified. Review your payout before confirming.',
            },
            'completed' => 'Conversion complete. Your wallet has been credited.',
            'failed' => $atc->provider_message === 'recipient_unavailable'
                ? 'No receiving line is available for this conversion. Your wallet has not been credited.'
                : 'Conversion failed. Your wallet has not been credited.',
            'expired', 'session_expired' => 'Verification expired. Start a new conversion with a fresh quote.',
            'provider_confirmed', 'settlement_pending' => 'Conversion confirmed. Wallet credit is being completed.',
            default => 'Your conversion needs confirmation. Do not send another transfer.',
        };

        return ['lifecycle' => $atc->lifecycle, 'resumed' => (bool) $atc->getAttribute('resumed'), 'id' => $atc->id, 'network_id' => $atc->network_id, 'network' => $atc->network, 'processing_mode' => 'provider',
            'amount' => (float) $atc->amount, 'payout_amount' => (float) $atc->payout_amount, 'sender_phone' => $atc->sender_phone,
            'status' => $atc->status,
            'transfer_attempted' => (int) $atc->provider_attempt_count > 0, 'state' => $state, 'message' => $message, 'reference' => $atc->transaction_reference,
            'expires_at' => $atc->expires_at?->toIso8601String(), 'airtime_balance' => $atc->provider_metadata['airtime_balance'] ?? null];
    }

    public function adminSettings()
    {
        return $this->success([...$this->configuration->runtime(), 'providers' => $this->manager->settings()]);
    }

    public function updateSettings(Request $request, string $provider)
    {
        $input = $request->validate([
            'enabled' => 'required|boolean',
            'priority' => 'required|integer|min:1|max:100',
            'base_url' => 'sometimes|string|max:255',
            'token' => 'sometimes|nullable|string|max:4096',
        ]);
        try {
            $this->manager->get($provider);
            $this->configuration->updateProvider($provider, $input);
        } catch (\DomainException $e) {
            return $this->fail([], $e->getMessage(), 422);
        }

        return $this->adminSettings();
    }

    public function updateRuntime(Request $request)
    {
        $input = $request->validate([
            'mode_enabled' => 'required|boolean',
            'live_calls_enabled' => 'required|boolean',
        ]);
        try {
            $this->configuration->updateRuntime($input['mode_enabled'], $input['live_calls_enabled']);
        } catch (\DomainException $e) {
            return $this->fail([], $e->getMessage(), 422);
        }

        return $this->adminSettings();
    }

    public function testConnection(string $provider)
    {
        try {
            $result = $this->manager->testConnection($provider);

            return $this->success([
                'connected' => $result->connected,
                'message' => $result->message,
                'checked_at' => now()->toIso8601String(),
            ], $result->message);
        } catch (\DomainException $e) {
            return $this->fail([], $e->getMessage(), 422);
        }
    }

    public function reconcile(int $id, AirtimeToCashReconciliationService $reconciliation)
    {
        try {
            $atc = $reconciliation->reconcile($id)->load(['user:id,username,email,phone', 'reviewer:id,username']);

            return $this->success([...$atc->toArray(), 'provider' => $atc->provider]);
        } catch (\DomainException $e) {
            return $this->fail([], $e->getMessage(), 422);
        }
    }
}
