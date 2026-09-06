<?php

namespace App\Http\Controllers;

use App\Models\AirtimeToCashProviderSetting;
use App\Models\AirtimeToCashRequest;
use App\Services\AirtimeToCash\AirtimeToCashProviderManager;
use App\Services\AirtimeToCash\AirtimeToCashProviderService;
use App\Services\AirtimeToCash\AirtimeToCashReconciliationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class AirtimeToCashProviderController extends Controller
{
    public function __construct(private AirtimeToCashProviderService $flow, private AirtimeToCashProviderManager $manager) {}

    public function options()
    {
        return $this->success(['provider_available' => $this->manager->modeAvailable() && collect($this->manager->settings())->contains(fn ($item) => $item['enabled'] && $item['configured'])]);
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

    public function status(Request $request, int $id)
    {
        $atc = AirtimeToCashRequest::where('user_id', $request->user()->id)->where('processing_mode', 'provider')->findOrFail($id);

        // Local-only read: no hidden polling or telecom request.
        return $this->success($this->customerView($atc));
    }

    public function customerView(AirtimeToCashRequest $atc): array
    {
        $message = match ($atc->provider_status) {
            'awaiting_otp' => $atc->provider_message === 'failed' ? 'Verification failed. Check the code and try again.' : 'Enter the code sent to your SIM.',
            'ready_to_transfer' => 'SIM verified. Review your payout before confirming.',
            'completed' => 'Conversion complete. Your wallet has been credited.',
            'failed' => 'Conversion failed. Your wallet has not been credited.',
            'expired', 'session_expired' => 'Verification expired. Restart verification to continue.',
            'provider_confirmed', 'settlement_pending' => 'Conversion confirmed. Wallet credit is being completed.',
            default => 'Your conversion needs confirmation. Do not send another transfer.',
        };

        return ['id' => $atc->id, 'network_id' => $atc->network_id, 'network' => $atc->network, 'processing_mode' => 'provider',
            'amount' => (float) $atc->amount, 'payout_amount' => (float) $atc->payout_amount, 'sender_phone' => $atc->sender_phone,
            'state' => $atc->provider_status, 'message' => $message, 'reference' => $atc->transaction_reference,
            'expires_at' => $atc->expires_at?->toIso8601String(), 'airtime_balance' => $atc->provider_metadata['airtime_balance'] ?? null];
    }

    public function adminSettings()
    {
        return $this->success(['mode_enabled' => (bool) config('airtime_to_cash.provider_mode_enabled'),
            'live_calls_enabled' => (bool) config('airtime_to_cash.live_calls_enabled'), 'providers' => $this->manager->settings()]);
    }

    public function updateSettings(Request $request, string $provider)
    {
        $this->manager->get($provider);
        $input = $request->validate(['enabled' => 'required|boolean', 'priority' => 'required|integer|min:1|max:100']);
        AirtimeToCashProviderSetting::updateOrCreate(['provider' => $provider], $input);

        return $this->adminSettings();
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
