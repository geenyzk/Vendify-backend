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
        // Each method has its own availability; a manual destination is not required for automation.
        $automated = $networks->contains(fn ($network) => $this->flow->inspect($network)['available']);

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
        // The provider needs the network's airtime-transfer PIN, never the Vendify transaction PIN.
        $network = self::networkName((string) $atc->network);
        $maxPins = AirtimeToCashProviderService::MAX_PIN_ATTEMPTS;
        $pinsLeft = max(0, $maxPins - (int) $atc->pin_attempt_count);
        // Definitive transfer rejections. `low_balance` is the reason name used before 2026-09-14.
        $rejection = match ($atc->provider_message) {
            'invalid_pin' => "{$network} Airtime Transfer PIN not accepted. Check that you're entering your {$network} airtime transfer PIN — not your Vendify transaction PIN — and try again.",
            'pin_attempts_exhausted' => "Your {$network} Airtime Transfer PIN was not accepted {$maxPins} times, so this conversion has been stopped. No airtime was converted and your wallet has not been credited. Check your {$network} airtime transfer PIN — not your Vendify transaction PIN — then start a new conversion.",
            'insufficient_balance', 'low_balance' => "There isn't enough transferable airtime on this SIM to complete the conversion. Your wallet has not been credited.",
            'session_rejected' => 'Your SIM verification was not accepted for this transfer. No airtime was converted and your wallet has not been credited. Start a new conversion to verify your SIM again.',
            default => null,
        };
        $message = match ($atc->status === 'failed' ? 'failed' : $state) {
            'created' => 'Preparing SIM verification. No transfer has been submitted.',
            'verifying_otp' => 'Checking your verification code. No transfer has been submitted.',
            'setup_review' => 'SIM setup could not be confirmed. No transfer has been submitted. Contact support or start again after verification expires.',
            'awaiting_otp' => $atc->provider_message === 'failed' ? 'Verification failed. Check the code and try again.' : 'Enter the code sent to your SIM.',
            'ready_to_transfer' => $atc->provider_message === 'invalid_pin'
                ? $rejection.' '.($pinsLeft === 1 ? 'You have 1 attempt left.' : "You have {$pinsLeft} attempts left.")
                : $rejection ?? 'SIM verified. Review your payout before confirming.',
            'completed' => 'Conversion complete. Your wallet has been credited.',
            'failed' => $atc->provider_message === 'recipient_unavailable'
                ? 'No receiving line is available for this conversion. Your wallet has not been credited.'
                : $rejection ?? 'Conversion failed. Your wallet has not been credited.',
            'expired', 'session_expired' => 'Verification expired. Start a new conversion with a fresh quote.',
            'provider_confirmed', 'settlement_pending' => 'Conversion confirmed. Wallet credit is being completed.',
            default => 'Your conversion needs confirmation. Do not send another transfer.',
        };

        return ['lifecycle' => $atc->lifecycle, 'resumed' => (bool) $atc->getAttribute('resumed'), 'id' => $atc->id, 'network_id' => $atc->network_id, 'network' => $atc->network, 'processing_mode' => 'provider',
            'amount' => (float) $atc->amount, 'payout_amount' => (float) $atc->payout_amount, 'sender_phone' => $atc->sender_phone,
            'status' => $atc->status,
            'transfer_attempted' => (int) $atc->provider_attempt_count > 0, 'state' => $state, 'message' => $message, 'reference' => $atc->transaction_reference,
            'pin_attempts_remaining' => $pinsLeft,
            'expires_at' => $atc->expires_at?->toIso8601String(), 'airtime_balance' => $atc->provider_metadata['airtime_balance'] ?? null];
    }

    /** Customer-facing network name used in airtime-transfer PIN wording ("MTN Airtime Transfer PIN"). */
    private static function networkName(string $network): string
    {
        return match (\App\Services\AirtimeToCashAvailabilityService::canonicalName($network)) {
            'mtn' => 'MTN', 'airtel' => 'Airtel', 'glo' => 'Glo', '9mobile' => '9mobile',
            default => trim($network) ?: 'Network',
        };
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
            'session_login_before_transfer' => 'sometimes|boolean',
        ]);
        try {
            $adapter = $this->manager->get($provider);
            if (array_key_exists('session_login_before_transfer', $input) && ! $adapter instanceof \App\Services\AirtimeToCash\ChecksSession) {
                throw new \DomainException('This provider does not use a session login.');
            }
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
