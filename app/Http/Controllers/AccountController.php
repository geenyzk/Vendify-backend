<?php

namespace App\Http\Controllers;

use App\Classes\Payment\Payment;
use App\Classes\Payment\PaymentFactory;
use App\Classes\Payment\Provider\PaymentPoint;
use App\Classes\SerivceControl\ServiceControlService;
use App\HttpResponse;
use App\Models\Bank;
use App\Models\PaymentPointProvisioning;
use App\Models\Provider;
use App\Models\User;
use App\Services\Auth\SessionSecurityService;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AccountController extends Controller
{
    use HttpResponse;

    /**
     * Update the authenticated user's own profile (name/phone only —
     * email and username double as login identifiers and aren't editable here).
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'fullname' => 'sometimes|string|max:255',
            'username' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('users', 'username')->ignore($user->id)->whereNull('deleted_at'),
            ],
            'email' => [
                'sometimes',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id)->whereNull('deleted_at'),
            ],
            'phone' => [
                'sometimes',
                'string',
                Rule::unique('users', 'phone')->ignore($user->id)->whereNull('deleted_at'),
            ],
        ]);

        $user->update($validated);

        return $this->success(['user' => $user->fresh()->load('role.permissions')]);
    }

    /**
     * Change the authenticated user's own password.
     */
    public function updatePassword(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        $user->update(['password' => Hash::make($validated['password'])]);

        app(SessionSecurityService::class)->revokeAllForUser($user, 'password_changed');
        AuditLogger::record(
            'password_changed',
            subject: $user,
            actor: $user,
            description: 'Password changed; all active sessions were revoked.',
        );

        return $this->success(null, 'Password updated successfully.');
    }

    /**
     * Set or change the authenticated user's transaction PIN. Only requires
     * the current PIN if one is already set (so the post-registration
     * "create your PIN" step doesn't need a current_pin field).
     */
    public function updatePin(Request $request)
    {
        $user = $request->user();

        // The web client uses camelCase form state, while the API contract is
        // snake_case. Normalize aliases here so a valid current PIN is not
        // reported as "required" simply because it arrived as currentPin.
        $request->merge([
            'pin_confirmation' => $request->input('pin_confirmation', $request->input('pinConfirmation')),
            'current_pin' => $request->input('current_pin', $request->input('currentPin')),
        ]);

        $validated = $request->validate([
            'pin' => ['required', 'digits:4'],
            'pin_confirmation' => ['required', 'same:pin'],
        ]);

        if ($user->pin) {
            // Creating a PIN is auto-submitted as soon as the fourth
            // confirmation digit is entered. A double tap/key repeat can send
            // an identical retry after the first request has already saved
            // the PIN. Treat that retry as success; it changes nothing and
            // avoids incorrectly asking a brand-new user for a current PIN.
            if (!$request->filled('current_pin') && Hash::check($validated['pin'], $user->pin)) {
                return $this->pinUpdatedResponse($user);
            }

            $currentPin = $request->validate([
                'current_pin' => ['required', 'digits:4'],
            ])['current_pin'];

            if (!ServiceControlService::verifyTransactionPin($user->id, $currentPin)) {
                return $this->fail(['current_pin' => ['Current PIN is incorrect.']], 'Current PIN is incorrect.', 422);
            }
        }

        $user->update(['pin' => $validated['pin']]);

        AuditLogger::record(
            'transaction_pin_changed',
            subject: $user,
            actor: $user,
            description: 'Transaction PIN was changed after recent authentication.',
        );

        return $this->pinUpdatedResponse($user);
    }

    /**
     * Reset a forgotten transaction PIN after the account password has been
     * confirmed by the recent.auth middleware. This deliberately lives on a
     * separate route so changing a known PIN still requires the current PIN.
     */
    public function resetPin(Request $request)
    {
        $user = $request->user();
        $validated = $request->validate([
            'pin' => ['required', 'digits:4', 'confirmed'],
        ]);

        $user->update(['pin' => $validated['pin']]);

        AuditLogger::record(
            'transaction_pin_reset',
            subject: $user,
            actor: $user,
            description: 'A forgotten transaction PIN was reset after password confirmation.',
        );

        return $this->pinUpdatedResponse($user);
    }

    private function pinUpdatedResponse(User $user): JsonResponse
    {
        // PIN setup is also used immediately after registration, before any
        // funding provider may have been configured. Return the lightweight
        // auth payload; serializing the default User appends would evaluate
        // Bank accessors and can dereference a missing provider's charge_type.
        $freshUser = $user->fresh()->load('role.permissions');
        $freshUser->setAppends(['has_pin']);

        return $this->success(
            ['user' => $freshUser],
            'Transaction PIN updated successfully.'
        );
    }

    /**
     * (Re)generate the authenticated user's virtual account(s) — one per
     * currently active payment provider. Normally done automatically at
     * register/login, but generation can fail silently (provider outage, no
     * active provider at the time) or a provider can be activated later, so
     * the wallet page offers this as a manual retry. Safe to call
     * repeatedly: PaymentBase::generateAccount() skips providers that already
     * have a usable Bank row.
     */
    public function generateVirtualAccounts(Request $request)
    {
        $user = $request->user();

        Payment::generateAccount($user);

        return $this->success(['user' => $user->fresh()->load('role.permissions')]);
    }

    public function fundingAccount(Request $request)
    {
        $user = $request->user();
        $correlationId = (string) ($request->attributes->get('request_id') ?: Str::uuid());
        Log::withContext(['correlation_id' => $correlationId]);

        $find = fn () => Bank::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->withUsableAccountDetails()
            ->whereIn(DB::raw("LOWER(REPLACE(provider, ' ', ''))"), Provider::query()
                ->getPaymentProviders()
                ->selectRaw("LOWER(REPLACE(name, ' ', ''))"))
            ->latest('id')->first();

        $account = $find();
        if (! $account) {
            $provider = Provider::query()->getPaymentProviders()->get()
                ->first(fn (Provider $candidate) => PaymentFactory::isPaymentPoint($candidate));

            if ($provider) {
                $state = PaymentPointProvisioning::query()->firstOrCreate(
                    ['user_id' => $user->id, 'provider_id' => $provider->id],
                    [
                        'status' => PaymentPointProvisioning::STATUS_KYC_REQUIRED,
                        'attempt_count' => 0,
                        'customer_created' => false,
                    ],
                );

                $canOfferIdentity = in_array($state->status, [
                    PaymentPointProvisioning::STATUS_KYC_REQUIRED,
                    PaymentPointProvisioning::STATUS_FAILED,
                ], true) && $state->kyc_attempted_at === null;

                if ($canOfferIdentity) {
                    return $this->identityRequiredResponse($state, $correlationId);
                }

                return $this->pendingSupportResponse($correlationId);
            }

            $provisioning = ['eligible_providers' => 0, 'outcomes' => []];
        } else {
            $provisioning = ['eligible_providers' => null, 'outcomes' => []];
        }

        if (!$account) {
            $outcomes = array_values($provisioning['outcomes']);
            $inProgress = in_array('in_progress', $outcomes, true);
            $configurationInvalid = in_array('configuration_invalid', $outcomes, true);
            $identityRequired = in_array('identity_required', $outcomes, true);
            $noProvider = $provisioning['eligible_providers'] === 0;

            $errorCode = $identityRequired
                ? 'identity_required'
                : ($inProgress
                ? 'provisioning_in_progress'
                : ($noProvider
                    ? 'provider_unavailable'
                    : ($configurationInvalid ? 'provider_configuration_invalid' : 'provider_request_failed')));
            $retryable = $inProgress;
            $status = $identityRequired ? 'identity_required' : ($inProgress ? 'pending' : 'failed');
            $httpStatus = $identityRequired ? 409 : ($inProgress ? 202 : ($errorCode === 'provider_request_failed' ? 502 : 503));

            Log::warning('funding_account.request_failed', [
                'stage' => 'controller',
                'user_id' => $user->id,
                'error_code' => $errorCode,
                'retryable' => $retryable,
                'provider_outcomes' => $outcomes,
            ]);

            return response()->json([
                'status' => $status,
                'error_code' => $errorCode,
                'message' => $identityRequired
                    ? 'PalmPay requires identity verification before it can issue your funding account.'
                    : ($inProgress
                        ? 'Your funding account request is already being processed.'
                        : 'Funding account creation is temporarily unavailable. Support can trace this request.'),
                'retryable' => $retryable,
                'kyc_submission_enabled' => $identityRequired && (bool) config('paymentpoint.kyc_recovery_enabled'),
                'correlation_id' => $correlationId,
            ], $httpStatus)->header('X-Correlation-ID', $correlationId);
        }

        Log::info('funding_account.request_ready', [
            'stage' => 'controller',
            'user_id' => $user->id,
            'bank_id' => $account->id,
            'provider' => $account->provider,
        ]);

        return response()->json([
            'status' => 'ready',
            'correlation_id' => $correlationId,
            'account' => [
                'account_number' => $account->bank_account,
                'bank_name' => $account->bank_name,
                'account_name' => $account->account_name,
                'currency' => $account->currency ?: 'NGN',
            ],
        ])->header('X-Correlation-ID', $correlationId);
    }

    public function submitFundingIdentity(Request $request)
    {
        $validated = $request->validate([
            'id_type' => ['required', Rule::in(['bvn', 'nin'])],
            'consent' => ['accepted'],
        ]);

        $idType = $validated['id_type'];
        $identityIsValid = preg_match(
            '/^\d{11}$/',
            (string) $request->attributes->get('funding_identity_number', ''),
        ) === 1;
        if (! $identityIsValid) {
            return response()->json([
                'status' => 'failed',
                'error_code' => 'invalid_identity_number',
                'message' => 'Enter a valid 11-digit BVN or NIN.',
                'retryable' => false,
                'errors' => ['id_number' => ['The identity number must be exactly 11 digits.']],
            ], 422);
        }

        $user = $request->user();
        $correlationId = (string) ($request->attributes->get('request_id') ?: Str::uuid());
        Log::withContext(['correlation_id' => $correlationId]);

        $existing = Bank::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->withUsableAccountDetails()
            ->whereRaw("LOWER(REPLACE(provider, ' ', '')) = ?", ['paymentpoint'])
            ->latest('id')
            ->first();

        if ($existing) {
            return response()->json([
                'status' => 'ready',
                'correlation_id' => $correlationId,
                'account' => [
                    'account_number' => $existing->bank_account,
                    'bank_name' => $existing->bank_name,
                    'account_name' => $existing->account_name,
                    'currency' => $existing->currency ?: 'NGN',
                ],
            ])->header('X-Correlation-ID', $correlationId);
        }

        $provider = Provider::query()->getPaymentProviders()->get()
            ->first(fn (Provider $candidate) => PaymentFactory::isPaymentPoint($candidate));

        if (! $provider) {
            return response()->json([
                'status' => 'failed',
                'error_code' => 'provider_unavailable',
                'message' => 'PalmPay verification is temporarily unavailable.',
                'retryable' => false,
                'correlation_id' => $correlationId,
            ], 503)->header('X-Correlation-ID', $correlationId);
        }

        $gateway = PaymentFactory::make($provider);
        if (! $gateway instanceof PaymentPoint) {
            return response()->json([
                'status' => 'failed',
                'error_code' => 'provider_configuration_invalid',
                'message' => 'PalmPay verification is temporarily unavailable.',
                'retryable' => false,
                'correlation_id' => $correlationId,
            ], 503)->header('X-Correlation-ID', $correlationId);
        }

        $idNumber = (string) $request->attributes->get('funding_identity_number');
        try {
            $outcome = $gateway->generateWithIdentity($user, $idType, $idNumber);
        } catch (\Throwable $exception) {
            Log::error('funding_account.identity_submission_failed', [
                'user_id' => $user->id,
                'provider_id' => $provider->id,
                'exception' => $exception::class,
            ]);

            return response()->json([
                'status' => 'failed',
                'error_code' => 'identity_verification_failed',
                'message' => 'PalmPay account activation could not be completed. Support can trace the request.',
                'retryable' => false,
                'correlation_id' => $correlationId,
            ], 409)->header('X-Correlation-ID', $correlationId);
        } finally {
            // Drop the controller-local copy before DB reads or response work.
            unset($idNumber);
        }

        $account = Bank::query()
            ->where('user_id', $user->id)
            ->where('provider', 'payment point')
            ->where('status', 'active')
            ->withUsableAccountDetails()
            ->latest('id')
            ->first();

        if ($account) {
            return response()->json([
                'status' => 'ready',
                'correlation_id' => $correlationId,
                'account' => [
                    'account_number' => $account->bank_account,
                    'bank_name' => $account->bank_name,
                    'account_name' => $account->account_name,
                    'currency' => $account->currency ?: 'NGN',
                ],
            ])->header('X-Correlation-ID', $correlationId);
        }

        $recoveryUnconfirmed = $outcome === 'kyc_recovery_unconfirmed';
        $alreadyAttempted = $outcome === 'kyc_attempt_already_used';

        if ($recoveryUnconfirmed) {
            return $this->pendingSupportResponse($correlationId);
        }

        return response()->json([
            'status' => 'failed',
            'error_code' => $alreadyAttempted ? 'kyc_attempt_already_used' : 'identity_verification_failed',
            'message' => 'PalmPay could not issue the account from this verification attempt. Support can trace the request.',
            'retryable' => false,
            'kyc_submission_enabled' => false,
            'correlation_id' => $correlationId,
        ], 409)->header('X-Correlation-ID', $correlationId);
    }

    private function identityRequiredResponse(PaymentPointProvisioning $state, string $correlationId): JsonResponse
    {
        $isRecovery = $state->isRecovery();
        $submissionEnabled = !$isRecovery || (bool) config('paymentpoint.kyc_recovery_enabled');

        if (! $submissionEnabled) {
            return $this->pendingSupportResponse($correlationId);
        }

        return response()->json([
            'status' => 'identity_required',
            'error_code' => 'identity_required',
            'message' => 'Verify your identity to activate wallet funding with a dedicated PalmPay account.',
            'retryable' => false,
            'kyc_submission_enabled' => true,
            'recovery_required' => $isRecovery,
            'correlation_id' => $correlationId,
        ], 409)->header('X-Correlation-ID', $correlationId);
    }

    private function pendingSupportResponse(string $correlationId): JsonResponse
    {
        return response()->json([
            'status' => 'recovery_pending',
            'error_code' => 'kyc_recovery_unconfirmed',
            'message' => 'Account activation is temporarily unavailable while we complete the recovery process. Please contact support if you need assistance.',
            'retryable' => false,
            'kyc_submission_enabled' => false,
            'recovery_required' => true,
            'correlation_id' => $correlationId,
        ], 409)->header('X-Correlation-ID', $correlationId);
    }
}
