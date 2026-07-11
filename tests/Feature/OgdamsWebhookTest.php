<?php

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    foreach ([
        'event_awards',
        'events',
        'cashback_rates',
        'settings',
        'transactions',
        'users',
    ] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('username')->unique();
        $table->string('fullname');
        $table->string('email')->unique();
        $table->timestamp('email_verified_at')->nullable();
        $table->string('phone')->unique();
        $table->string('password');
        $table->string('user_type')->default('user');
        $table->decimal('wallet_balance', 12, 2)->default(0);
        $table->decimal('referral_balance', 12, 2)->default(0);
        $table->decimal('total_referral_earnings', 12, 2)->default(0);
        $table->boolean('is_active')->default(true);
        $table->boolean('is_verified')->default(false);
        $table->string('status')->default('active');
        $table->string('pin')->nullable();
        $table->string('referral_code')->nullable();
        $table->unsignedBigInteger('referred_by')->nullable();
        $table->timestamp('last_login_at')->nullable();
        $table->rememberToken();
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('transactions', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->string('transaction_type');
        $table->string('provider')->nullable();
        $table->string('account_or_phone')->nullable();
        $table->decimal('amount', 15, 2);
        $table->decimal('cost', 15, 2)->nullable();
        $table->decimal('discount_amount', 15, 2)->default(0);
        $table->double('quantity', 8, 2)->default(1);
        $table->string('status');
        $table->string('transaction_reference')->unique();
        $table->string('payment_reference')->nullable();
        $table->string('funding_method')->nullable();
        $table->decimal('balance_before', 15, 2)->default(0);
        $table->decimal('balance_after', 15, 2)->default(0);
        $table->timestamp('completed_at')->nullable();
        $table->timestamp('refunded_at')->nullable();
        $table->string('refund_reason')->nullable();
        $table->string('response_message')->nullable();
        $table->decimal('service_fee', 15, 2)->default(0);
        $table->string('platform')->nullable();
        $table->string('receiver')->nullable();
        $table->string('plan_type')->nullable();
        $table->string('token')->nullable();
        $table->unsignedBigInteger('promotion_id')->nullable();
        $table->string('related_reference')->nullable();
        $table->timestamps();
    });

    Schema::create('settings', function (Blueprint $table) {
        $table->id();
        $table->decimal('referral_commission_rate', 8, 2)->default(0);
        $table->timestamps();
    });

    Schema::create('cashback_rates', function (Blueprint $table) {
        $table->id();
        $table->string('service_type');
        $table->decimal('percentage', 8, 2)->default(0);
        $table->boolean('active')->default(false);
        $table->timestamps();
    });

    Schema::create('events', function (Blueprint $table) {
        $table->id();
        $table->string('name')->nullable();
        $table->string('metric')->nullable();
        $table->decimal('threshold', 15, 2)->default(0);
        $table->string('service_type')->nullable();
        $table->string('reward_type')->default('badge');
        $table->decimal('cash_amount', 15, 2)->default(0);
        $table->boolean('repeatable')->default(false);
        $table->boolean('active')->default(false);
        $table->timestamps();
    });

    Schema::create('event_awards', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('event_id');
        $table->unsignedBigInteger('user_id');
        $table->unsignedInteger('times_earned')->default(0);
        $table->timestamp('last_earned_at')->nullable();
        $table->timestamps();
    });

    Notification::fake();
    Queue::fake();
});

function ogdamsUser(float $walletBalance = 900): User
{
    return User::create([
        'username' => 'ogdams_user_' . uniqid(),
        'fullname' => 'Ogdams User',
        'email' => uniqid('ogdams_') . '@example.com',
        'phone' => '080' . random_int(10000000, 99999999),
        'password' => Hash::make('password'),
        'wallet_balance' => $walletBalance,
        'status' => 'active',
    ]);
}

function ogdamsTransaction(User $user, array $overrides = []): Transaction
{
    return Transaction::create(array_merge([
        'user_id' => $user->id,
        'transaction_type' => 'data_subscription',
        'provider' => 'ogdams',
        'amount' => 100,
        'status' => 'pending',
        'transaction_reference' => 'OGD|13|20220328090850|' . random_int(1000, 9999),
        'balance_before' => 1000,
        'balance_after' => 900,
        'response_message' => 'Processing',
        'receiver' => '2349066685702',
        'platform' => 'api',
    ], $overrides));
}

function ogdamsPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'status' => true,
        'code' => 200,
        'event' => [
            'type' => 'data',
            'data' => [
                'network' => 1,
                'msg' => 'Data delivered.',
                'reference' => 'OGD|13|20220328090850|2095',
            ],
        ],
    ], $overrides);
}

function getOgdamsWebhook(array|string $payload)
{
    $content = is_string($payload) ? $payload : json_encode($payload);

    return test()->call(
        'GET',
        '/api/webhooks/ogdams',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ],
        $content,
    );
}

it('updates a pending transaction to successful', function () {
    $user = ogdamsUser();
    $transaction = ogdamsTransaction($user);

    $response = getOgdamsWebhook(ogdamsPayload([
        'event' => ['data' => ['reference' => $transaction->transaction_reference]],
    ]));

    $response->assertOk()->assertJson(['status' => true, 'message' => 'Webhook received']);
    $transaction->refresh();

    expect($transaction->status)->toBe('success');
    expect($transaction->response_message)->toBe('Data delivered.');
    expect($transaction->completed_at)->not->toBeNull();
    expect((float) $user->refresh()->wallet_balance)->toBe(900.0);
});

it('updates a pending transaction to failed', function () {
    $user = ogdamsUser();
    $transaction = ogdamsTransaction($user);

    getOgdamsWebhook(ogdamsPayload([
        'status' => false,
        'code' => 424,
        'event' => ['data' => [
            'reference' => $transaction->transaction_reference,
            'msg' => 'Invalid number.',
        ]],
    ]))->assertOk();

    $transaction->refresh();

    expect($transaction->status)->toBe('fail');
    expect($transaction->response_message)->toBe('Invalid number.');
});

it('refunds a failed pending transaction once', function () {
    $user = ogdamsUser();
    $transaction = ogdamsTransaction($user);

    getOgdamsWebhook(ogdamsPayload([
        'status' => false,
        'code' => 424,
        'event' => ['data' => ['reference' => $transaction->transaction_reference]],
    ]))->assertOk();

    expect((float) $user->refresh()->wallet_balance)->toBe(1000.0);
    expect(Transaction::where('related_reference', $transaction->transaction_reference)->count())->toBe(1);
    expect($transaction->refresh()->refunded_at)->not->toBeNull();
});

it('does not process a duplicate successful callback twice', function () {
    $user = ogdamsUser();
    $transaction = ogdamsTransaction($user);
    $payload = ogdamsPayload([
        'event' => ['data' => ['reference' => $transaction->transaction_reference]],
    ]);

    getOgdamsWebhook($payload)->assertOk();
    $completedAt = $transaction->refresh()->completed_at;
    getOgdamsWebhook($payload)->assertOk();

    expect($transaction->refresh()->completed_at->toDateTimeString())->toBe($completedAt->toDateTimeString());
    expect((float) $user->refresh()->wallet_balance)->toBe(900.0);
});

it('does not refund a duplicate failed callback twice', function () {
    $user = ogdamsUser();
    $transaction = ogdamsTransaction($user);
    $payload = ogdamsPayload([
        'status' => false,
        'code' => 424,
        'event' => ['data' => ['reference' => $transaction->transaction_reference]],
    ]);

    getOgdamsWebhook($payload)->assertOk();
    getOgdamsWebhook($payload)->assertOk();

    expect((float) $user->refresh()->wallet_balance)->toBe(1000.0);
    expect(Transaction::where('related_reference', $transaction->transaction_reference)->count())->toBe(1);
});

it('does not affect wallet or transactions for an unknown reference', function () {
    $user = ogdamsUser();
    $transaction = ogdamsTransaction($user);

    getOgdamsWebhook(ogdamsPayload())->assertOk();

    expect($transaction->refresh()->status)->toBe('pending');
    expect((float) $user->refresh()->wallet_balance)->toBe(900.0);
    expect(Transaction::count())->toBe(1);
});

it('returns 422 for invalid json', function () {
    getOgdamsWebhook('{bad json')->assertStatus(422)->assertJson([
        'status' => false,
        'message' => 'Invalid webhook payload',
    ]);
});

it('returns 422 for missing required fields', function () {
    getOgdamsWebhook(['status' => true])->assertStatus(422)->assertJson([
        'status' => false,
        'message' => 'Invalid webhook payload',
    ]);
});

it('does not change a successful transaction to failed later', function () {
    $user = ogdamsUser();
    $transaction = ogdamsTransaction($user, [
        'status' => 'success',
        'completed_at' => now(),
    ]);

    getOgdamsWebhook(ogdamsPayload([
        'status' => false,
        'code' => 424,
        'event' => ['data' => ['reference' => $transaction->transaction_reference]],
    ]))->assertOk();

    expect($transaction->refresh()->status)->toBe('success');
    expect((float) $user->refresh()->wallet_balance)->toBe(900.0);
});

it('does not refund an already failed and refunded transaction again', function () {
    $user = ogdamsUser(1000);
    $transaction = ogdamsTransaction($user, [
        'status' => 'fail',
        'balance_after' => 1000,
        'refunded_at' => now(),
        'refund_reason' => 'Already refunded',
    ]);

    getOgdamsWebhook(ogdamsPayload([
        'status' => false,
        'code' => 424,
        'event' => ['data' => ['reference' => $transaction->transaction_reference]],
    ]))->assertOk();

    expect((float) $user->refresh()->wallet_balance)->toBe(1000.0);
    expect(Transaction::where('related_reference', $transaction->transaction_reference)->count())->toBe(0);
});

it('parses raw json bodies sent with get requests', function () {
    $user = ogdamsUser();
    $transaction = ogdamsTransaction($user);

    getOgdamsWebhook(ogdamsPayload([
        'event' => ['data' => ['reference' => $transaction->transaction_reference]],
    ]))->assertOk();

    expect($transaction->refresh()->status)->toBe('success');
});
