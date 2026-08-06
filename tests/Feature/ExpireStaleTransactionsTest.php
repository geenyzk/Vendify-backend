<?php

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    foreach ([
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
        $table->string('phone')->unique();
        $table->string('password');
        $table->decimal('wallet_balance', 15, 2)->default(0);
        $table->boolean('is_active')->default(true);
        $table->boolean('is_verified')->default(false);
        $table->string('status')->default('active');
        $table->rememberToken();
        $table->timestamps();
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
});

function makeTransaction(User $user, array $overrides = []): Transaction
{
    return Transaction::create(array_merge([
        'user_id' => $user->id,
        'transaction_type' => 'data_subscription',
        'provider' => 'ogdams',
        'account_or_phone' => '2348012345678',
        'amount' => 500,
        'cost' => 450,
        'discount_amount' => 0,
        'quantity' => 1,
        'status' => 'pending',
        'transaction_reference' => 'TXN-' . uniqid(),
        'balance_before' => 1500,
        'balance_after' => 1000,
        'response_message' => 'Processing',
        'platform' => 'api',
    ], $overrides));
}

function makeUser(float $walletBalance = 1000): User
{
    return User::create([
        'username' => 'expire_user_' . uniqid(),
        'fullname' => 'Expire User',
        'email' => uniqid('expire_') . '@example.com',
        'phone' => '080' . random_int(10000000, 99999999),
        'password' => Hash::make('password'),
        'wallet_balance' => $walletBalance,
        'status' => 'active',
    ]);
}

it('expires stale pending transactions and refunds the customer wallet', function () {
    $user = makeUser(500.00);
    $transaction = makeTransaction($user, [
        'created_at' => now()->subHours(25),
        'balance_before' => 1500,
        'balance_after' => 1000,
    ]);

    $this->artisan('transactions:expire-stale --hours=24')->assertExitCode(0);

    $transaction->refresh();
    $user->refresh();

    expect($transaction->status)->toBe('fail')
        ->and($transaction->response_message)->toBe('Expired pending transaction after 24 hour(s).')
        ->and((float) $user->wallet_balance)->toBe(1000.00);
});

it('does not modify transactions during dry-run', function () {
    $user = makeUser(500.00);
    $transaction = makeTransaction($user, [
        'created_at' => now()->subHours(25),
        'balance_before' => 1500,
        'balance_after' => 1000,
    ]);

    $this->artisan('transactions:expire-stale --hours=24 --dry-run')
        ->assertExitCode(0)
        ->expectsOutput('DRY-RUN: 1 pending transaction(s) would be expired.');

    $transaction->refresh();
    $user->refresh();

    expect($transaction->status)->toBe('pending')
        ->and((float) $user->wallet_balance)->toBe(500.00);
});
