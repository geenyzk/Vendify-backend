<?php

use App\Classes\Payment\Payment;
use App\Classes\Payment\PaymentFactory;
use App\Classes\Payment\WebhookOutcome;
use App\Jobs\AutoFundVendor;
use App\Models\Bank;
use App\Models\Provider;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WalletWithdrawal;
use App\Services\Payments\WalletWithdrawalSettlementService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;

function flwCreateTransactionsTable(): void
{
    Schema::create('transactions', function (Blueprint $table) {
        $table->id();
        $table->string('user_id');
        $table->string('transaction_type');
        $table->string('provider')->nullable();
        $table->string('account_or_phone')->nullable();
        $table->decimal('amount', 15, 2);
        $table->decimal('cost', 15, 2)->nullable();
        $table->decimal('discount_amount', 15, 2)->default(0);
        $table->decimal('quantity', 8, 2)->default(1);
        $table->string('status');
        $table->string('transaction_reference')->unique();
        $table->string('payment_reference')->nullable();
        $table->string('provider_transaction_id')->nullable();
        $table->unique(['provider', 'provider_transaction_id'], 'transactions_provider_payment_unique');
        $table->string('funding_method')->nullable();
        $table->decimal('balance_before', 15, 2)->default(0);
        $table->decimal('balance_after', 15, 2)->default(0);
        $table->timestamp('completed_at')->nullable();
        $table->string('response_message')->nullable();
        $table->decimal('service_fee', 15, 2)->default(0);
        $table->string('platform')->nullable();
        $table->string('receiver')->nullable();
        $table->string('plan_type')->nullable();
        $table->string('token')->nullable();
        $table->string('related_reference')->nullable();
        $table->timestamp('refunded_at')->nullable();
        $table->string('refund_reason')->nullable();
        $table->timestamps();
    });
}

function flwProvider(array $overrides = []): Provider
{
    return Provider::create(array_merge([
        'name' => 'flutterwave',
        'category' => 'payment',
        'sub_category' => 'payment',
        'identifier' => 'flutterwave-test',
        'secret_key' => 'test-secret',
        'webhook_access' => 'test-hash',
        'charge_fee' => 0,
        'charge_type' => 'fiat',
        'active' => true,
    ], $overrides));
}

function flwUser(float $balance = 0): User
{
    return User::create([
        'username' => 'payer-' . Illuminate\Support\Str::random(6),
        'fullname' => 'Test Payer',
        'email' => Illuminate\Support\Str::random(6) . '@example.test',
        'phone' => '08012345678',
        'password' => 'password',
        'wallet_balance' => $balance,
        'is_active' => true,
        'status' => User::STATUS_ACTIVE,
    ]);
}

function flwAccount(User $user, string $txRef = 'STATIC-ACCOUNT-REF'): Bank
{
    return Bank::create([
        'user_id' => $user->id,
        'provider' => 'flutterwave',
        'status' => 'active',
        'tx_ref' => $txRef,
        'bank_account' => '1234567890',
        'bank_name' => 'Test Bank',
        'account_name' => $user->fullname,
        'currency' => 'NGN',
    ]);
}

function flwEvent(User $user, int $id = 7001, float $amount = 1000, string $txRef = 'STATIC-ACCOUNT-REF'): array
{
    return [
        'event' => 'charge.completed',
        'data' => [
            'id' => $id,
            'status' => 'successful',
            'amount' => $amount,
            'currency' => 'NGN',
            'tx_ref' => $txRef,
            'flw_ref' => "FLW-REF-{$id}",
            'customer' => ['email' => $user->email, 'phone_number' => $user->phone],
        ],
    ];
}

function flwFakeVerification(User $user, int $id = 7001, float $amount = 1000, string $txRef = 'STATIC-ACCOUNT-REF', array $overrides = []): void
{
    $data = array_replace_recursive([
        'id' => $id,
        'status' => 'successful',
        'amount' => $amount,
        'currency' => 'NGN',
        'tx_ref' => $txRef,
        'flw_ref' => "FLW-REF-{$id}",
        'app_fee' => 0,
        'processor_response' => 'Approved',
        'customer' => ['email' => $user->email, 'phone_number' => $user->phone],
    ], $overrides);
    Http::fake(["*/transactions/{$id}/verify" => Http::response(['status' => 'success', 'data' => $data])]);
}

function flwRequest(array $payload, string $hash = 'test-hash'): Request
{
    return Request::create('/api/webhook/payment/flutterwave-test', 'POST', $payload, [], [], [
        'HTTP_VERIF_HASH' => $hash,
    ]);
}

function flwWithdrawal(User $user, array $overrides = []): WalletWithdrawal
{
    return WalletWithdrawal::create(array_merge([
        'user_id' => $user->id,
        'amount' => 100,
        'fee' => 10,
        'bank_code' => '044',
        'bank_name' => 'Test Bank',
        'account_number' => '1234567890',
        'account_name' => $user->fullname,
        'status' => WalletWithdrawal::STATUS_PROCESSING,
        'transaction_reference' => 'WD-' . Illuminate\Support\Str::random(10),
    ], $overrides));
}

beforeEach(function () {
    foreach (['wallet_withdrawals', 'transactions', 'banks', 'service_controls', 'providers', 'settings', 'users'] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('username')->unique();
        $table->string('fullname')->nullable();
        $table->string('email')->unique();
        $table->string('phone')->nullable();
        $table->string('password')->nullable();
        $table->string('pin')->nullable();
        $table->decimal('wallet_balance', 15, 2)->default(0);
        $table->decimal('referral_balance', 15, 2)->default(0);
        $table->decimal('total_referral_earnings', 15, 2)->default(0);
        $table->string('referral_code')->nullable();
        $table->string('status')->nullable();
        $table->boolean('is_active')->default(true);
        $table->boolean('is_verified')->default(true);
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('providers', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('category')->nullable();
        $table->string('sub_category')->nullable();
        $table->string('identifier')->nullable();
        $table->string('secret_key')->nullable();
        $table->string('webhook_access')->nullable();
        $table->decimal('charge_fee', 15, 2)->default(0);
        $table->decimal('charge_fee_cap', 15, 2)->nullable();
        $table->string('charge_type')->nullable();
        $table->decimal('withdrawal_fee', 15, 2)->default(0);
        $table->string('withdrawal_fee_type')->default('fiat');
        $table->boolean('active')->default(true);
        $table->boolean('auto_fund_enabled')->default(false);
        $table->decimal('auto_fund_threshold', 15, 2)->nullable();
        $table->decimal('auto_fund_amount', 15, 2)->nullable();
        $table->string('account_number')->nullable();
        $table->string('account_name')->nullable();
        $table->string('bank_code')->nullable();
        $table->string('bank_name')->nullable();
        $table->unsignedBigInteger('funding_provider_id')->nullable();
        $table->timestamps();
    });
    Schema::create('service_controls', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->boolean('isActive')->default(true);
        $table->boolean('isDevLock')->default(false);
        $table->timestamps();
    });
    Schema::create('banks', function (Blueprint $table) {
        $table->id();
        $table->string('user_id');
        $table->string('provider');
        $table->string('status')->default('active');
        $table->string('tx_ref')->nullable();
        $table->string('bank_account')->nullable();
        $table->string('bank_name')->nullable();
        $table->string('account_name')->nullable();
        $table->string('currency')->nullable();
        $table->timestamps();
    });
    flwCreateTransactionsTable();
    Schema::create('wallet_withdrawals', function (Blueprint $table) {
        $table->id();
        $table->string('user_id');
        $table->decimal('amount', 15, 2);
        $table->decimal('fee', 15, 2)->default(0);
        $table->string('bank_code');
        $table->string('bank_name');
        $table->string('account_number');
        $table->string('account_name');
        $table->string('status')->default('pending');
        $table->string('rejection_reason')->nullable();
        $table->string('gateway_reference')->nullable();
        $table->string('gateway_transfer_id')->nullable()->unique();
        $table->string('transaction_reference')->unique();
        $table->string('reviewed_by')->nullable();
        $table->timestamp('reviewed_at')->nullable();
        $table->timestamp('completed_at')->nullable();
        $table->timestamp('refunded_at')->nullable();
        $table->timestamps();
    });
    Schema::create('settings', function (Blueprint $table) {
        $table->id();
        $table->string('invoice_prefix')->nullable();
        $table->string('invoice_suffix')->nullable();
        $table->timestamps();
    });

    Notification::fake();
    Http::preventStrayRequests();
});

it('credits one verified Flutterwave deposit', function () {
    $provider = flwProvider();
    $user = flwUser();
    flwAccount($user);
    flwFakeVerification($user);

    expect(PaymentFactory::make($provider)->webhook(flwRequest(flwEvent($user))))->toBe(WebhookOutcome::Accepted)
        ->and((float) $user->fresh()->wallet_balance)->toBe(1000.0)
        ->and(Transaction::where('provider_transaction_id', '7001')->count())->toBe(1);
});

it('credits a retried deposit only once', function () {
    $provider = flwProvider();
    $user = flwUser();
    flwAccount($user);
    flwFakeVerification($user);
    $gateway = PaymentFactory::make($provider);
    $gateway->webhook(flwRequest(flwEvent($user)));
    $gateway->webhook(flwRequest(flwEvent($user)));

    expect((float) $user->fresh()->wallet_balance)->toBe(1000.0)
        ->and(Transaction::count())->toBe(1);
});

it('credits separate deposits to the same static account reference', function () {
    $provider = flwProvider();
    $user = flwUser();
    flwAccount($user);
    $gateway = PaymentFactory::make($provider);
    flwFakeVerification($user, 7001, 1000);
    $gateway->webhook(flwRequest(flwEvent($user, 7001, 1000)));
    flwFakeVerification($user, 7002, 500);
    $gateway->webhook(flwRequest(flwEvent($user, 7002, 500)));

    expect((float) $user->fresh()->wallet_balance)->toBe(1500.0)
        ->and(Transaction::count())->toBe(2);
});

it('rejects a webhook amount that differs from verification', function () {
    $provider = flwProvider();
    $user = flwUser();
    flwAccount($user);
    flwFakeVerification($user, 7001, 900);
    PaymentFactory::make($provider)->webhook(flwRequest(flwEvent($user, 7001, 1000)));
    expect((float) $user->fresh()->wallet_balance)->toBe(0.0);
});

it('rejects a verified non-NGN deposit', function () {
    $provider = flwProvider();
    $user = flwUser();
    flwAccount($user);
    flwFakeVerification($user, overrides: ['currency' => 'USD']);
    PaymentFactory::make($provider)->webhook(flwRequest(flwEvent($user)));
    expect((float) $user->fresh()->wallet_balance)->toBe(0.0);
});

it('rejects a verified non-successful deposit', function () {
    $provider = flwProvider();
    $user = flwUser();
    flwAccount($user);
    flwFakeVerification($user, overrides: ['status' => 'failed']);
    PaymentFactory::make($provider)->webhook(flwRequest(flwEvent($user)));
    expect(Transaction::count())->toBe(0);
});

it('rejects a mismatched merchant reference', function () {
    $provider = flwProvider();
    $user = flwUser();
    flwAccount($user);
    flwFakeVerification($user, txRef: 'OTHER-REF');
    PaymentFactory::make($provider)->webhook(flwRequest(flwEvent($user)));
    expect((float) $user->fresh()->wallet_balance)->toBe(0.0);
});

it('rejects a mismatched Flutterwave reference', function () {
    $provider = flwProvider();
    $user = flwUser();
    flwAccount($user);
    flwFakeVerification($user, overrides: ['flw_ref' => 'OTHER-FLW-REF']);
    PaymentFactory::make($provider)->webhook(flwRequest(flwEvent($user)));
    expect(Transaction::count())->toBe(0);
});

it('rejects a deposit for an unknown virtual account', function () {
    $provider = flwProvider();
    $user = flwUser();
    flwFakeVerification($user);
    PaymentFactory::make($provider)->webhook(flwRequest(flwEvent($user)));
    expect((float) $user->fresh()->wallet_balance)->toBe(0.0);
});

it('rejects a deposit when the verified customer does not own the account', function () {
    $provider = flwProvider();
    $user = flwUser();
    flwAccount($user);
    flwFakeVerification($user, overrides: ['customer' => ['email' => 'attacker@example.test']]);
    PaymentFactory::make($provider)->webhook(flwRequest(flwEvent($user)));
    expect(Transaction::count())->toBe(0);
});

it('returns 401 for an invalid webhook signature', function () {
    $provider = flwProvider();
    $user = flwUser();
    $response = Payment::webhook(flwRequest(flwEvent($user), 'wrong-hash'), $provider->identifier);
    expect($response->getStatusCode())->toBe(401);
});

it('requests a retry when verification times out', function () {
    $provider = flwProvider();
    $user = flwUser();
    flwAccount($user);
    Http::fake(fn () => throw new ConnectionException('timeout'));
    expect(PaymentFactory::make($provider)->webhook(flwRequest(flwEvent($user))))->toBe(WebhookOutcome::Retry);
});

it('acknowledges a permanent verification failure without credit', function () {
    $provider = flwProvider();
    $user = flwUser();
    flwAccount($user);
    Http::fake(['*' => Http::response(['status' => 'error'], 404)]);
    expect(PaymentFactory::make($provider)->webhook(flwRequest(flwEvent($user))))->toBe(WebhookOutcome::Accepted)
        ->and((float) $user->fresh()->wallet_balance)->toBe(0.0);
});

it('settles a valid in-flight webhook after the gateway is disabled', function () {
    $provider = flwProvider(['active' => false]);
    $user = flwUser();
    flwAccount($user);
    flwFakeVerification($user);
    $response = Payment::webhook(flwRequest(flwEvent($user)), $provider->identifier);
    expect($response->getStatusCode())->toBe(200)
        ->and((float) $user->fresh()->wallet_balance)->toBe(1000.0);
});

it('can retry safely after a database failure', function () {
    $provider = flwProvider();
    $user = flwUser();
    flwAccount($user);
    flwFakeVerification($user);
    $gateway = PaymentFactory::make($provider);
    Schema::drop('transactions');
    expect($gateway->webhook(flwRequest(flwEvent($user))))->toBe(WebhookOutcome::Retry);
    flwCreateTransactionsTable();
    expect($gateway->webhook(flwRequest(flwEvent($user))))->toBe(WebhookOutcome::Accepted)
        ->and((float) $user->fresh()->wallet_balance)->toBe(1000.0);
});

it('applies the configured deposit fee to the verified amount', function () {
    $provider = flwProvider(['charge_fee' => 2, 'charge_type' => 'percent']);
    $user = flwUser();
    flwAccount($user);
    flwFakeVerification($user);
    PaymentFactory::make($provider)->webhook(flwRequest(flwEvent($user)));
    expect((float) $user->fresh()->wallet_balance)->toBe(980.0);
});

it('persists the provider transaction identity', function () {
    $provider = flwProvider();
    $user = flwUser();
    flwAccount($user);
    flwFakeVerification($user);
    PaymentFactory::make($provider)->webhook(flwRequest(flwEvent($user)));
    expect(Transaction::first()->provider_transaction_id)->toBe('7001');
});

it('records wallet balances around a deposit', function () {
    $provider = flwProvider();
    $user = flwUser(25);
    flwAccount($user);
    flwFakeVerification($user);
    PaymentFactory::make($provider)->webhook(flwRequest(flwEvent($user)));
    $transaction = Transaction::first();
    expect((float) $transaction->balance_before)->toBe(25.0)
        ->and((float) $transaction->balance_after)->toBe(1025.0);
});

it('treats an accepted transfer create response as processing', function () {
    $gateway = PaymentFactory::make(flwProvider());
    Http::fake(['*/transfers' => Http::response([
        'status' => 'success',
        'message' => 'Transfer queued',
        'data' => ['id' => 91, 'reference' => 'WD-QUEUED', 'status' => 'NEW'],
    ])]);
    $result = $gateway->transfer([
        'account_bank' => '044',
        'account_number' => '1234567890',
        'amount' => 100,
        'narration' => 'Test',
        'reference' => 'WD-QUEUED',
    ]);
    expect($result['status'])->toBe('processing');
});

it('marks a verified successful withdrawal terminal', function () {
    $gateway = PaymentFactory::make(flwProvider());
    $user = flwUser(900);
    $withdrawal = flwWithdrawal($user);
    $gateway->settleWithdrawal([
        'id' => 91, 'reference' => $withdrawal->transaction_reference,
        'currency' => 'NGN', 'amount' => 100, 'status' => 'SUCCESSFUL',
    ]);
    expect($withdrawal->fresh()->status)->toBe(WalletWithdrawal::STATUS_SUCCESSFUL)
        ->and($withdrawal->fresh()->completed_at)->not->toBeNull()
        ->and((float) $user->fresh()->wallet_balance)->toBe(900.0);
});

it('refunds a verified failed withdrawal', function () {
    $gateway = PaymentFactory::make(flwProvider());
    $user = flwUser(900);
    $withdrawal = flwWithdrawal($user);
    $gateway->settleWithdrawal([
        'id' => 92, 'reference' => $withdrawal->transaction_reference,
        'currency' => 'NGN', 'amount' => 100, 'status' => 'FAILED',
        'complete_message' => 'Rejected',
    ]);
    expect($withdrawal->fresh()->status)->toBe(WalletWithdrawal::STATUS_FAILED)
        ->and((float) $user->fresh()->wallet_balance)->toBe(1010.0);
});

it('refunds a failed withdrawal exactly once across duplicate events', function () {
    $gateway = PaymentFactory::make(flwProvider());
    $user = flwUser(900);
    $withdrawal = flwWithdrawal($user);
    $verified = [
        'id' => 93, 'reference' => $withdrawal->transaction_reference,
        'currency' => 'NGN', 'amount' => 100, 'status' => 'FAILED',
    ];
    $gateway->settleWithdrawal($verified);
    $gateway->settleWithdrawal($verified);
    expect((float) $user->fresh()->wallet_balance)->toBe(1010.0)
        ->and(Transaction::where('related_reference', $withdrawal->transaction_reference)->count())->toBe(1);
});

it('keeps a non-terminal verified transfer processing', function () {
    $gateway = PaymentFactory::make(flwProvider());
    $user = flwUser(900);
    $withdrawal = flwWithdrawal($user);
    $gateway->settleWithdrawal([
        'id' => 94, 'reference' => $withdrawal->transaction_reference,
        'currency' => 'NGN', 'amount' => 100, 'status' => 'PENDING',
    ]);
    expect($withdrawal->fresh()->status)->toBe(WalletWithdrawal::STATUS_PROCESSING)
        ->and((float) $user->fresh()->wallet_balance)->toBe(900.0);
});

it('does not settle mismatched transfer details', function () {
    $gateway = PaymentFactory::make(flwProvider());
    $user = flwUser(900);
    $withdrawal = flwWithdrawal($user);
    expect(fn () => $gateway->settleWithdrawal([
        'id' => 95, 'reference' => $withdrawal->transaction_reference,
        'currency' => 'USD', 'amount' => 100, 'status' => 'FAILED',
    ]))->toThrow(App\Classes\Payment\InvalidPaymentWebhookException::class);
    expect((float) $user->fresh()->wallet_balance)->toBe(900.0);
});

it('uses the current all-balances endpoint and selects NGN', function () {
    $gateway = PaymentFactory::make(flwProvider());
    Http::fake(['*/balances' => Http::response(['status' => 'success', 'data' => [
        ['currency' => 'USD', 'available_balance' => 3],
        ['currency' => 'NGN', 'available_balance' => 4500],
    ]])]);
    expect($gateway->checkBalance())->toBe('4500');
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v3/balances'));
});

it('hides disabled Flutterwave funding accounts from the user accessor', function () {
    flwProvider(['active' => false]);
    $user = flwUser();
    flwAccount($user);
    expect($user->banks)->toHaveCount(0);
});

it('shows an existing account again after Flutterwave is re-enabled', function () {
    $provider = flwProvider(['active' => false]);
    Illuminate\Support\Facades\DB::table('service_controls')->insert([
        'name' => 'flutterwave', 'isActive' => true, 'isDevLock' => false,
    ]);
    $user = flwUser();
    flwAccount($user);
    expect($user->banks)->toHaveCount(0);
    $provider->update(['active' => true]);
    $user->unsetRelation('banks');
    expect($user->banks)->toHaveCount(1);
});

it('requires a configured identity before creating a permanent account', function () {
    $gateway = PaymentFactory::make(flwProvider());
    $user = flwUser();
    expect($gateway->generate($user))->toBeNull();
    Http::assertNothingSent();
});

it('keeps failed withdrawal settlement idempotent at the service boundary', function () {
    $user = flwUser(900);
    $withdrawal = flwWithdrawal($user);
    $service = app(WalletWithdrawalSettlementService::class);
    $service->markFailed($withdrawal, 'failed');
    $service->markFailed($withdrawal, 'failed again');
    expect((float) $user->fresh()->wallet_balance)->toBe(1010.0);
});

it('settles a successful withdrawal webhook only after provider verification', function () {
    $provider = flwProvider();
    $user = flwUser(900);
    $withdrawal = flwWithdrawal($user, ['gateway_transfer_id' => '101']);
    Http::fake(['*/transfers/101' => Http::response(['status' => 'success', 'data' => [
        'id' => 101,
        'reference' => $withdrawal->transaction_reference,
        'currency' => 'NGN',
        'amount' => 100,
        'status' => 'SUCCESSFUL',
    ]])]);

    $outcome = PaymentFactory::make($provider)->webhook(flwRequest([
        'event' => 'transfer.completed',
        'data' => ['id' => 101, 'status' => 'SUCCESSFUL'],
    ]));

    expect($outcome)->toBe(WebhookOutcome::Accepted)
        ->and($withdrawal->fresh()->status)->toBe(WalletWithdrawal::STATUS_SUCCESSFUL);
});

it('settles and refunds a failed withdrawal webhook once', function () {
    $provider = flwProvider();
    $user = flwUser(900);
    $withdrawal = flwWithdrawal($user, ['gateway_transfer_id' => '102']);
    Http::fake(['*/transfers/102' => Http::response(['status' => 'success', 'data' => [
        'id' => 102,
        'reference' => $withdrawal->transaction_reference,
        'currency' => 'NGN',
        'amount' => 100,
        'status' => 'FAILED',
    ]])]);
    $gateway = PaymentFactory::make($provider);
    $request = flwRequest(['event' => 'transfer.completed', 'data' => ['id' => 102]]);
    $gateway->webhook($request);
    $gateway->webhook($request);

    expect($withdrawal->fresh()->status)->toBe(WalletWithdrawal::STATUS_FAILED)
        ->and((float) $user->fresh()->wallet_balance)->toBe(1010.0);
});

it('creates withdrawals in a non-terminal pending state', function () {
    $user = flwUser(900);
    $withdrawal = flwWithdrawal($user, ['status' => WalletWithdrawal::STATUS_PENDING]);
    expect($withdrawal->status)->toBe(WalletWithdrawal::STATUS_PENDING)
        ->and($withdrawal->completed_at)->toBeNull();
});

it('removes withdrawal capability when Flutterwave is disabled', function () {
    flwProvider(['active' => false]);
    Illuminate\Support\Facades\DB::table('service_controls')->insert([
        'name' => 'flutterwave', 'isActive' => true, 'isDevLock' => false,
    ]);
    expect(PaymentFactory::makeTransferCapable())->toBeNull();
});

it('blocks vendor auto-funding through a disabled Flutterwave provider', function () {
    $provider = flwProvider(['active' => false]);
    Illuminate\Support\Facades\DB::table('service_controls')->insert([
        'name' => 'flutterwave', 'isActive' => true, 'isDevLock' => false,
    ]);
    $vendor = Vendor::create([
        'name' => 'Test Vendor',
        'category' => 'vendor',
        'auto_fund_enabled' => true,
        'auto_fund_amount' => 100,
        'account_number' => '1234567890',
        'bank_code' => '044',
        'funding_provider_id' => $provider->id,
    ]);

    (new AutoFundVendor($vendor, 0))->handle();
    Http::assertNothingSent();
});

it('enforces one database claim for simultaneous duplicate payment identities', function () {
    $user = flwUser();
    $base = [
        'user_id' => $user->id,
        'transaction_type' => 'wallet_funding',
        'provider' => 'flutterwave',
        'provider_transaction_id' => 'CONCURRENT-1',
        'amount' => 100,
        'status' => 'pending',
        'funding_method' => 'bank_transfer',
    ];
    Transaction::create($base + ['transaction_reference' => 'FLW-DEP-CONCURRENT-A']);

    expect(fn () => Transaction::create($base + ['transaction_reference' => 'FLW-DEP-CONCURRENT-B']))
        ->toThrow(Illuminate\Database\QueryException::class);
});

it('ignores duplicate success and later failure events after withdrawal success', function () {
    $gateway = PaymentFactory::make(flwProvider());
    $user = flwUser(900);
    $withdrawal = flwWithdrawal($user);
    $success = [
        'id' => 110, 'reference' => $withdrawal->transaction_reference,
        'currency' => 'NGN', 'amount' => 100, 'status' => 'SUCCESSFUL',
    ];
    $gateway->settleWithdrawal($success);
    $gateway->settleWithdrawal($success);
    $gateway->settleWithdrawal(array_replace($success, ['status' => 'FAILED']));

    expect($withdrawal->fresh()->status)->toBe(WalletWithdrawal::STATUS_SUCCESSFUL)
        ->and((float) $user->fresh()->wallet_balance)->toBe(900.0);
});
