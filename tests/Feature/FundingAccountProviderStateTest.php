<?php

use App\Models\Bank;
use App\Http\Controllers\ServiceControlController;
use App\Models\Provider;
use App\Models\PaymentPointProvisioning;
use App\Models\ServiceControl;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('username')->unique();
        $table->string('fullname');
        $table->string('email')->unique();
        $table->string('phone')->unique();
        $table->string('password');
        $table->decimal('wallet_balance', 15, 2)->default(0);
        $table->string('status')->default('active');
        $table->string('referral_code')->nullable();
        $table->rememberToken();
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('providers', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('category')->nullable();
        $table->string('sub_category')->nullable();
        $table->boolean('active')->default(false);
        $table->string('username')->nullable();
        $table->string('password')->nullable();
        $table->string('api_key')->nullable();
        $table->string('webhook_access')->default('1');
        $table->timestamps();
    });

    Schema::create('service_controls', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('category');
        $table->string('sub_category');
        $table->boolean('isActive');
        $table->boolean('isDevLock');
        $table->timestamps();
    });

    Schema::create('banks', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->string('account_type')->nullable();
        $table->string('bank_account')->nullable();
        $table->string('bank_name')->nullable();
        $table->string('account_name')->nullable();
        $table->string('provider')->nullable();
        $table->string('status')->nullable();
        $table->string('currency')->nullable();
        $table->string('ref')->nullable();
        $table->string('tx_ref')->nullable();
        $table->decimal('amount', 8, 2)->default(0);
        $table->timestamp('expired_at')->nullable();
        $table->timestamps();
    });

    Schema::create('payment_point_provisionings', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->unsignedBigInteger('provider_id');
        $table->string('status', 32);
        $table->string('last_error_category', 64)->nullable();
        $table->boolean('customer_created')->default(false);
        $table->unsignedTinyInteger('attempt_count')->default(0);
        $table->timestamp('last_attempt_at')->nullable();
        $table->timestamp('kyc_consent_at')->nullable();
        $table->timestamp('kyc_attempted_at')->nullable();
        $table->timestamps();
        $table->unique(['user_id', 'provider_id']);
    });
});

afterEach(function () {
    Schema::dropIfExists('payment_point_provisionings');
    Schema::dropIfExists('banks');
    Schema::dropIfExists('service_controls');
    Schema::dropIfExists('providers');
    Schema::dropIfExists('users');
});

function fundingAccountUser(): User
{
    return User::query()->create([
        'username' => 'funding-user',
        'fullname' => 'Funding User',
        'email' => 'funding@example.com',
        'phone' => '08012345678',
        'password' => 'password',
        'status' => 'active',
    ]);
}

function fundingProvider(string $name, bool $providerActive, bool $controlActive): void
{
    $isPaymentPoint = strtolower(str_replace(' ', '', $name)) === 'paymentpoint';
    Provider::query()->create([
        'name' => $name,
        'category' => 'payment',
        'sub_category' => 'payment',
        'active' => $providerActive,
        'username' => $isPaymentPoint ? 'business-id' : null,
        'password' => $isPaymentPoint ? 'api-token' : null,
        'api_key' => $isPaymentPoint ? 'api-key' : null,
        'webhook_access' => $isPaymentPoint ? 'webhook-secret' : '1',
    ]);

    ServiceControl::query()->create([
        'name' => $name,
        'category' => 'transaction',
        'sub_category' => 'payment gateway',
        'isActive' => $controlActive,
        'isDevLock' => false,
    ]);
}

it('does not display a saved account belonging to a disabled provider', function () {
    $user = fundingAccountUser();
    fundingProvider('flutterwave', false, false);
    fundingProvider('PaymentPoint', true, true);

    Bank::query()->create([
        'user_id' => $user->id,
        'account_type' => 'virtual',
        'bank_account' => '9907389272',
        'bank_name' => 'Indulge MFB',
        'provider' => 'flutterwave',
        'status' => 'active',
    ]);
    Bank::query()->create([
        'user_id' => $user->id,
        'account_type' => 'virtual',
        'bank_account' => '6679854996',
        'bank_name' => 'PalmPay',
        'provider' => 'payment point',
        'status' => 'active',
    ]);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/wallet/funding-account')
        ->assertOk()
        ->assertJsonPath('status', 'ready')
        ->assertJsonPath('account.account_number', '6679854996')
        ->assertJsonPath('account.bank_name', 'PalmPay');
});

it('does not display an incomplete account row as ready', function () {
    $user = fundingAccountUser();
    fundingProvider('PaymentPoint', true, true);

    Bank::query()->create([
        'user_id' => $user->id,
        'account_type' => 'virtual',
        'bank_account' => '6679854996',
        'bank_name' => 'PalmPay',
        'provider' => 'payment point',
        'status' => 'active',
    ]);
    Bank::query()->create([
        'user_id' => $user->id,
        'account_type' => 'virtual',
        'bank_account' => '   ',
        'bank_name' => '',
        'provider' => 'payment point',
        'status' => 'active',
    ]);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/wallet/funding-account')
        ->assertOk()
        ->assertJsonPath('status', 'ready')
        ->assertJsonPath('account.account_number', '6679854996')
        ->assertJsonPath('account.bank_name', 'PalmPay');
});

it('reports a traceable provider-unavailable failure without polling forever', function () {
    $user = fundingAccountUser();

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/wallet/funding-account')
        ->assertServiceUnavailable()
        ->assertJsonPath('status', 'failed')
        ->assertJsonPath('error_code', 'provider_unavailable')
        ->assertJsonPath('retryable', false)
        ->assertJsonStructure(['correlation_id']);

    $response->assertHeader('X-Correlation-ID', $response->json('correlation_id'));
});

it('offers identity first then persists one PalmPay account without a duplicate provider call', function () {
    $user = fundingAccountUser();
    fundingProvider('PaymentPoint', true, true);

    Http::fake([
        'api.paymentpoint.co/*' => Http::response([
            'status' => 'success',
            'bankAccounts' => [[
                'accountNumber' => '6698059290',
                'accountName' => 'Test (PaymentPoint)',
                'bankName' => 'PalmPay',
                'Reserved_Account_Id' => 'reserved-id',
            ]],
            'errors' => [],
        ]),
    ]);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/wallet/funding-account')
        ->assertStatus(409)
        ->assertJsonPath('status', 'identity_required')
        ->assertJsonPath('kyc_submission_enabled', true);

    Http::assertNothingSent();

    $created = $this->actingAs($user, 'sanctum')
        ->postJson('/api/wallet/funding-account/identity', [
            'id_type' => 'bvn',
            'id_number' => '22123456789',
            'consent' => true,
        ])
        ->assertOk()
        ->assertJsonPath('status', 'ready')
        ->assertJsonPath('account.account_number', '6698059290');
    $created->assertHeader('X-Correlation-ID', $created->json('correlation_id'));

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/wallet/funding-account')
        ->assertOk()
        ->assertJsonPath('status', 'ready')
        ->assertJsonPath('account.account_number', '6698059290');

    Http::assertSentCount(1);
    expect(Bank::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and((float) $user->fresh()->wallet_balance)->toBe(0.0);
});

it('keeps a previously failed allocation in recovery without calling create again on refresh', function () {
    $user = fundingAccountUser();
    fundingProvider('PaymentPoint', true, true);
    $provider = Provider::query()->where('name', 'PaymentPoint')->firstOrFail();

    PaymentPointProvisioning::query()->create([
        'user_id' => $user->id,
        'provider_id' => $provider->id,
        'status' => PaymentPointProvisioning::STATUS_KYC_REQUIRED,
        'customer_created' => true,
        'attempt_count' => 1,
    ]);

    Http::fake();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/wallet/funding-account')
        ->assertStatus(409)
        ->assertJsonPath('status', 'recovery_pending')
        ->assertJsonPath('error_code', 'kyc_recovery_unconfirmed')
        ->assertJsonPath('kyc_submission_enabled', false)
        ->assertJsonPath('retryable', false);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/wallet/funding-account')
        ->assertStatus(409)
        ->assertJsonPath('status', 'recovery_pending');

    Http::assertNothingSent();
    expect(PaymentPointProvisioning::query()->where('user_id', $user->id)->value('attempt_count'))->toBe(1)
        ->and(PaymentPointProvisioning::query()->where('user_id', $user->id)->value('customer_created'))->toBeTruthy();
});

it('submits a consented identity once and never persists the raw number', function () {
    config()->set('paymentpoint.kyc_recovery_enabled', true);
    Log::spy();
    $user = fundingAccountUser();
    fundingProvider('PaymentPoint', true, true);
    $provider = Provider::query()->where('name', 'PaymentPoint')->firstOrFail();

    PaymentPointProvisioning::query()->create([
        'user_id' => $user->id,
        'provider_id' => $provider->id,
        'status' => PaymentPointProvisioning::STATUS_KYC_REQUIRED,
        'attempt_count' => 1,
    ]);

    Http::fake([
        'api.paymentpoint.co/*' => Http::response([
            'status' => 'success',
            'bankAccounts' => [[
                'accountNumber' => '6698059290',
                'accountName' => 'Test (PaymentPoint)',
                'bankName' => 'PalmPay',
                'Reserved_Account_Id' => 'reserved-id',
            ]],
            'errors' => [],
        ], 201),
    ]);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/wallet/funding-account')
        ->assertStatus(409)
        ->assertJsonPath('status', 'identity_required')
        ->assertJsonPath('kyc_submission_enabled', true)
        ->assertJsonPath('recovery_required', true);

    Http::assertNothingSent();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/wallet/funding-account/identity', [
            'id_type' => 'bvn',
            'id_number' => '22123456789',
            'consent' => true,
        ])
        ->assertOk()
        ->assertJsonPath('status', 'ready')
        ->assertJsonPath('account.bank_name', 'PalmPay');

    // A repeated browser submission returns the persisted account and never
    // reaches PaymentPoint again.
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/wallet/funding-account/identity', [
            'id_type' => 'bvn',
            'id_number' => '22123456789',
            'consent' => true,
        ])
        ->assertOk()
        ->assertJsonPath('status', 'ready')
        ->assertJsonPath('account.account_number', '6698059290');

    Http::assertSent(fn (\Illuminate\Http\Client\Request $request) =>
        $request['bankCode'] === ['20946']
        && $request['idType'] === 'bvn'
        && $request['idNumber'] === '22123456789'
    );
    Http::assertSentCount(1);

    $storedState = PaymentPointProvisioning::query()->where('user_id', $user->id)->firstOrFail();
    expect($storedState->status)->toBe(PaymentPointProvisioning::STATUS_READY)
        ->and($storedState->kyc_consent_at)->not->toBeNull()
        ->and($storedState->kyc_attempted_at)->not->toBeNull()
        ->and(json_encode($storedState->getAttributes()))->not->toContain('22123456789')
        ->and((float) $user->fresh()->wallet_balance)->toBe(0.0);

    Log::shouldHaveReceived('info')->withArgs(
        fn (string $event, array $context) => $event === 'funding_account.provider_request'
            && ($context['has_identity'] ?? false) === true
            && !str_contains((string) json_encode($context), '22123456789'),
    )->once();
});

it('does not transmit identity while duplicate-safe recovery is disabled', function () {
    config()->set('paymentpoint.kyc_recovery_enabled', false);
    $user = fundingAccountUser();
    fundingProvider('PaymentPoint', true, true);
    $provider = Provider::query()->where('name', 'PaymentPoint')->firstOrFail();

    PaymentPointProvisioning::query()->create([
        'user_id' => $user->id,
        'provider_id' => $provider->id,
        'status' => PaymentPointProvisioning::STATUS_KYC_REQUIRED,
        'attempt_count' => 1,
    ]);
    Http::fake();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/wallet/funding-account/identity', [
            'id_type' => 'nin',
            'id_number' => '22123456789',
            'consent' => true,
        ])
        ->assertStatus(409)
        ->assertJsonPath('status', 'recovery_pending')
        ->assertJsonPath('error_code', 'kyc_recovery_unconfirmed')
        ->assertJsonPath('retryable', false);

    Http::assertNothingSent();
    expect(PaymentPointProvisioning::query()->where('user_id', $user->id)->value('kyc_attempted_at'))->toBeNull();
});

it('offers a previously failed pre-kyc record when safe recovery is enabled', function () {
    config()->set('paymentpoint.kyc_recovery_enabled', true);
    $user = fundingAccountUser();
    fundingProvider('PaymentPoint', true, true);
    $provider = Provider::query()->where('name', 'PaymentPoint')->firstOrFail();

    PaymentPointProvisioning::query()->create([
        'user_id' => $user->id,
        'provider_id' => $provider->id,
        'status' => PaymentPointProvisioning::STATUS_FAILED,
        'last_error_category' => 'provider_exception',
        'attempt_count' => 1,
    ]);

    Http::fake([
        'api.paymentpoint.co/*' => Http::response([
            'status' => 'success',
            'bankAccounts' => [[
                'accountNumber' => '6698059290',
                'accountName' => 'Recovered Customer',
                'bankName' => 'PalmPay',
            ]],
            'errors' => [],
        ], 201),
    ]);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/wallet/funding-account')
        ->assertStatus(409)
        ->assertJsonPath('status', 'identity_required')
        ->assertJsonPath('kyc_submission_enabled', true)
        ->assertJsonPath('recovery_required', true);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/wallet/funding-account/identity', [
            'id_type' => 'nin',
            'id_number' => '22123456789',
            'consent' => true,
        ])
        ->assertOk()
        ->assertJsonPath('status', 'ready')
        ->assertJsonPath('account.account_number', '6698059290');

    Http::assertSentCount(1);
    expect(PaymentPointProvisioning::query()->where('user_id', $user->id)->value('status'))
        ->toBe(PaymentPointProvisioning::STATUS_READY);
});

it('rejects an invalid identity number before any provider request', function () {
    $user = fundingAccountUser();
    fundingProvider('PaymentPoint', true, true);
    Http::fake();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/wallet/funding-account')
        ->assertStatus(409);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/wallet/funding-account/identity', [
            'id_type' => 'bvn',
            'id_number' => '1234',
            'consent' => true,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('error_code', 'invalid_identity_number');

    Http::assertNothingSent();
});

it('does not retry after a consented request returns no bank account', function () {
    $user = fundingAccountUser();
    fundingProvider('PaymentPoint', true, true);
    Http::fake([
        'api.paymentpoint.co/*' => Http::response([
            'status' => 'success',
            'customer' => ['customer_id' => 'provider-customer-id'],
            'bankAccounts' => [],
            'errors' => [['code' => 'ALLOCATION_PENDING']],
        ], 201),
    ]);

    $this->actingAs($user, 'sanctum')->getJson('/api/wallet/funding-account')->assertStatus(409);
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/wallet/funding-account/identity', [
            'id_type' => 'nin',
            'id_number' => '22123456789',
            'consent' => true,
        ])
        ->assertStatus(409)
        ->assertJsonPath('status', 'failed')
        ->assertJsonPath('retryable', false);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/wallet/funding-account')
        ->assertStatus(409)
        ->assertJsonPath('status', 'recovery_pending')
        ->assertJsonPath('error_code', 'kyc_recovery_unconfirmed')
        ->assertJsonPath('kyc_submission_enabled', false);

    Http::assertSentCount(1);
    expect(PaymentPointProvisioning::query()->where('user_id', $user->id)->value('attempt_count'))->toBe(1)
        ->and(Bank::query()->where('user_id', $user->id)->count())->toBe(0)
        ->and((float) $user->fresh()->wallet_balance)->toBe(0.0);
});

it('does not retry after a provider timeout with an ambiguous remote outcome', function () {
    $user = fundingAccountUser();
    fundingProvider('PaymentPoint', true, true);
    Http::fake(['api.paymentpoint.co/*' => Http::failedConnection('timed out')]);

    $this->actingAs($user, 'sanctum')->getJson('/api/wallet/funding-account')->assertStatus(409);
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/wallet/funding-account/identity', [
            'id_type' => 'bvn',
            'id_number' => '22123456789',
            'consent' => true,
        ])
        ->assertStatus(409)
        ->assertJsonPath('retryable', false);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/wallet/funding-account')
        ->assertStatus(409)
        ->assertJsonPath('status', 'recovery_pending')
        ->assertJsonPath('error_code', 'kyc_recovery_unconfirmed');

    Http::assertSentCount(1);
});

it('does not make a provider request merely because an onboarding wallet is opened', function () {
    $user = fundingAccountUser();
    fundingProvider('PaymentPoint', true, true);
    Http::fake();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/wallet/funding-account')
        ->assertStatus(409)
        ->assertJsonPath('status', 'identity_required')
        ->assertJsonPath('kyc_submission_enabled', true);

    Http::assertNothingSent();
});

it('does not load inactive providers for account generation', function () {
    fundingProvider('flutterwave', false, true);
    fundingProvider('PaymentPoint', true, true);

    expect(Provider::query()->getPaymentProviders()->pluck('name')->all())
        ->toBe(['PaymentPoint']);
});

it('matches enabled PaymentPoint controls regardless of spaces or case', function () {
    fundingAccountUser();
    fundingProvider('PaymentPoint', true, true);

    ServiceControl::query()->where('name', 'PaymentPoint')->update([
        'name' => 'payment point',
    ]);

    expect(Provider::query()->getPaymentProviders()->pluck('name')->all())
        ->toBe(['PaymentPoint']);
});

it('never uses a dev locked PaymentPoint control', function () {
    fundingAccountUser();
    fundingProvider('PaymentPoint', true, true);

    ServiceControl::query()->where('name', 'PaymentPoint')->update([
        'isDevLock' => true,
    ]);

    expect(Provider::query()->getPaymentProviders()->get())->toBeEmpty();
});

it('repairs a locked PaymentPoint control while preserving an enabled duplicate', function () {
    ServiceControl::query()->create([
        'name' => 'payment point',
        'category' => 'transaction',
        'sub_category' => 'payment gateway',
        'isActive' => false,
        'isDevLock' => true,
    ]);
    ServiceControl::query()->create([
        'name' => 'PaymentPoint',
        'category' => 'transaction',
        'sub_category' => 'payment gateway',
        'isActive' => true,
        'isDevLock' => false,
    ]);

    $migration = require database_path('migrations/2026_10_08_230000_repair_payment_point_service_control.php');
    $migration->up();

    $controls = ServiceControl::query()
        ->whereRaw("LOWER(REPLACE(TRIM(name), ' ', '')) = ?", ['paymentpoint'])
        ->get();

    expect($controls)->toHaveCount(1)
        ->and($controls->first()->name)->toBe('payment point')
        ->and($controls->first()->isActive)->toBeTrue()
        ->and($controls->first()->isDevLock)->toBeFalse();
});

it('unlocks the existing PaymentPoint control without changing other dev locks', function () {
    fundingProvider('PaymentPoint', true, true);
    fundingProvider('paystack', true, false);

    ServiceControl::query()->update(['isDevLock' => true]);
    ServiceControl::query()->where('name', 'PaymentPoint')->update(['name' => 'payment point']);

    (new ServiceControlController)->index();

    expect(ServiceControl::query()->whereRaw("LOWER(REPLACE(TRIM(name), ' ', '')) = ?", ['paymentpoint'])->count())
        ->toBe(1)
        ->and(ServiceControl::query()->where('name', 'payment point')->value('isDevLock'))->toBeFalse()
        ->and(ServiceControl::query()->where('name', 'paystack')->value('isDevLock'))->toBeTrue();
});
