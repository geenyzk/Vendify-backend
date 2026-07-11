<?php

use App\Models\Sim;
use App\Models\SimDevice;
use App\Models\SimVendJob;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

/**
 * Money-movement half of SIM vending: a device ack (or the expiry sweeper)
 * settles the customer's pending transaction through the same
 * settleCallback() path vendor webhooks use. The invariants that matter:
 * refund exactly once, never re-settle a terminal job (409), and the ack
 * route must bind BOTH {slug} and {id} positionally (the child-directive
 * ack bug).
 */

const SIM_SETTLE_SECRET = 'sim-settle-secret';

function createSimSettleTables(): void
{
    Schema::create('sim_devices', function (Blueprint $table) {
        $table->id();
        $table->timestamps();
        $table->string('name');
        $table->string('slug')->unique();
        $table->text('shared_secret')->nullable();
        $table->string('status')->default('active');
        $table->timestamp('last_seen_at')->nullable();
        $table->string('app_version')->nullable();
        $table->text('config')->nullable();
        $table->string('registration_code')->nullable();
        $table->timestamp('registration_code_expires_at')->nullable();
        $table->timestamp('registered_at')->nullable();
    });

    Schema::create('sims', function (Blueprint $table) {
        $table->id();
        $table->timestamps();
        $table->unsignedBigInteger('sim_device_id');
        $table->unsignedTinyInteger('slot_index');
        $table->string('network');
        $table->string('phone_number')->nullable();
        $table->boolean('supports_airtime')->default(true);
        $table->boolean('supports_data')->default(false);
        $table->decimal('airtime_balance', 12, 2)->default(0);
        $table->decimal('data_balance_mb', 12, 2)->default(0);
        $table->decimal('airtime_low_threshold', 12, 2)->default(1000);
        $table->decimal('data_low_threshold_mb', 12, 2)->default(1024);
        $table->boolean('enabled')->default(true);
        $table->timestamp('balance_reported_at')->nullable();
        $table->string('notes')->nullable();
    });

    Schema::create('sim_vend_jobs', function (Blueprint $table) {
        $table->id();
        $table->timestamps();
        $table->string('transaction_reference')->unique();
        $table->unsignedBigInteger('user_id');
        $table->string('service');
        $table->string('network');
        $table->string('phone');
        $table->decimal('amount', 12, 2);
        $table->unsignedBigInteger('data_plan_id')->nullable();
        $table->text('plan_snapshot')->nullable();
        $table->string('status')->default('pending');
        $table->unsignedTinyInteger('attempts')->default(0);
        $table->unsignedTinyInteger('max_attempts')->default(2);
        $table->unsignedBigInteger('sim_device_id')->nullable();
        $table->unsignedBigInteger('sim_id')->nullable();
        $table->timestamp('claimed_at')->nullable();
        $table->timestamp('lease_expires_at')->nullable();
        $table->timestamp('acked_at')->nullable();
        $table->text('result')->nullable();
        $table->string('failure_reason')->nullable();
    });

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

    Schema::create('providers', function (Blueprint $table) {
        $table->id();
        $table->timestamps();
        $table->string('base_url')->nullable();
        $table->string('name')->nullable();
        $table->string('username')->nullable();
        $table->string('password')->nullable();
        $table->boolean('active')->default(false);
        $table->string('api_key')->nullable();
        $table->string('webhook_access')->default('1');
        $table->string('identifier')->nullable();
        $table->string('category')->nullable();
        $table->string('sub_category')->nullable();
    });

    // Reward plumbing the pending→success settle path walks through — all
    // left empty so no rewards fire, but the tables must exist.
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
}

function dropSimSettleTables(): void
{
    foreach ([
        'event_awards', 'events', 'cashback_rates', 'settings', 'providers',
        'transactions', 'users', 'sim_vend_jobs', 'sims', 'sim_devices',
    ] as $table) {
        Schema::dropIfExists($table);
    }
}

/**
 * A full in-flight vend: funds already debited (wallet shows post-debit
 * balance), a pending transaction, and a job claimed by an online device.
 *
 * @return array{0: User, 1: Transaction, 2: SimVendJob, 3: SimDevice}
 */
function makeSimSettleWorld(float $amount = 500, float $walletAfterDebit = 1000): array
{
    Vendor::create([
        'name' => 'SIM Vending',
        'sub_category' => 'simvend',
        'active' => true,
        'identifier' => 'simvendtest',
    ]);

    $user = User::create([
        'username' => 'sim_user_' . uniqid(),
        'fullname' => 'Sim User',
        'email' => uniqid('sim_') . '@example.com',
        'phone' => '081' . random_int(10000000, 99999999),
        'password' => Hash::make('password'),
        'wallet_balance' => $walletAfterDebit,
        'status' => 'active',
    ]);

    $ref = 'SIMSETTLE-' . uniqid();

    $transaction = Transaction::create([
        'user_id' => $user->id,
        'transaction_type' => 'airtime_recharge',
        'provider' => 'simvend',
        'amount' => $amount,
        'status' => 'pending',
        'transaction_reference' => $ref,
        'platform' => 'sim',
    ]);

    $device = SimDevice::create([
        'name' => 'Settle Phone',
        'slug' => 'sim-settle-device',
        'shared_secret' => SIM_SETTLE_SECRET,
        'status' => 'active',
        'last_seen_at' => now(),
        'registered_at' => now(),
    ]);

    $sim = Sim::create([
        'sim_device_id' => $device->id,
        'slot_index' => 0,
        'network' => 'mtn',
        'airtime_balance' => 10000,
        'enabled' => true,
    ]);

    $job = SimVendJob::create([
        'transaction_reference' => $ref,
        'user_id' => $user->id,
        'service' => 'airtime',
        'network' => 'mtn',
        'phone' => '08012345678',
        'amount' => $amount,
        'status' => SimVendJob::STATUS_CLAIMED,
        'attempts' => 1,
        'sim_device_id' => $device->id,
        'sim_id' => $sim->id,
        'claimed_at' => now(),
        'lease_expires_at' => now()->addSeconds((int) config('simvending.lease_seconds')),
    ]);

    return [$user, $transaction, $job, $device];
}

function signedSimSettleAck($test, SimVendJob $job, array $body)
{
    $raw = json_encode($body);
    $timestamp = (string) time();

    return $test->call('POST', "/api/sim/sim-settle-device/jobs/{$job->id}/ack", [], [], [], [
        'HTTP_X_SIM_DEVICE' => 'sim-settle-device',
        'HTTP_X_TIMESTAMP' => $timestamp,
        'HTTP_X_SIGNATURE' => hash_hmac('sha256', "{$timestamp}.{$raw}", SIM_SETTLE_SECRET),
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], $raw);
}

beforeEach(function () {
    dropSimSettleTables();
    createSimSettleTables();
    Notification::fake();
    Queue::fake();
});

afterEach(function () {
    dropSimSettleTables();
});

test('an executed ack settles the pending transaction as success without touching the wallet', function () {
    [$user, $transaction, $job] = makeSimSettleWorld();

    $response = signedSimSettleAck($this, $job, [
        'result' => 'executed',
        'note' => 'USSD confirmed',
        'sim' => ['airtime_balance' => 9400],
    ]);

    expect($response->getStatusCode())->toBe(200);

    $job->refresh();
    $transaction->refresh();
    expect($job->status)->toBe(SimVendJob::STATUS_SUCCESS)
        ->and($job->acked_at)->not->toBeNull()
        ->and($transaction->status)->toBe('success')
        // Funds were reserved at purchase time — success keeps them.
        ->and((float) $user->fresh()->wallet_balance)->toBe(1000.0)
        // The ack's reported SIM balance is applied.
        ->and((float) Sim::find($job->sim_id)->airtime_balance)->toBe(9400.0);
});

test('a terminal failed ack refunds the customer exactly once', function () {
    [$user, $transaction, $job] = makeSimSettleWorld(500, 1000);

    $response = signedSimSettleAck($this, $job, [
        'result' => 'failed',
        'note' => 'USSD rejected by network',
        'retryable' => false,
    ]);

    expect($response->getStatusCode())->toBe(200);

    $job->refresh();
    $transaction->refresh();
    expect($job->status)->toBe(SimVendJob::STATUS_FAILED)
        ->and($transaction->status)->toBe('fail')
        ->and((float) $user->fresh()->wallet_balance)->toBe(1500.0);

    // A duplicate ack must 409 and never move money again.
    $dup = signedSimSettleAck($this, $job, ['result' => 'failed']);
    expect($dup->getStatusCode())->toBe(409)
        ->and((float) $user->fresh()->wallet_balance)->toBe(1500.0);
});

test('a retryable failure requeues the job without settling the transaction', function () {
    [$user, $transaction, $job] = makeSimSettleWorld();

    $response = signedSimSettleAck($this, $job, [
        'result' => 'failed',
        'note' => 'SIM busy',
        'retryable' => true,
    ]);

    expect($response->getStatusCode())->toBe(200);

    $job->refresh();
    $transaction->refresh();
    expect($job->status)->toBe(SimVendJob::STATUS_PENDING)
        ->and($job->sim_device_id)->toBeNull()
        ->and($job->attempts)->toBe(1)
        ->and($transaction->status)->toBe('pending')
        ->and((float) $user->fresh()->wallet_balance)->toBe(1000.0);
});

test('a retryable failure with no attempts left settles as failed', function () {
    [$user, $transaction, $job] = makeSimSettleWorld();
    $job->update(['attempts' => 2, 'max_attempts' => 2]);

    signedSimSettleAck($this, $job, ['result' => 'failed', 'retryable' => true]);

    expect($job->refresh()->status)->toBe(SimVendJob::STATUS_FAILED)
        ->and($transaction->refresh()->status)->toBe('fail')
        ->and((float) $user->fresh()->wallet_balance)->toBe(1500.0);
});

test('the sweeper refunds a lease-expired claimed job and a late executed ack cannot re-settle it', function () {
    [$user, $transaction, $job] = makeSimSettleWorld();
    $job->update([
        'lease_expires_at' => now()->subSeconds((int) config('simvending.lease_grace') + 60),
    ]);

    $this->artisan('sim:expire-jobs')->assertExitCode(0);

    $job->refresh();
    $transaction->refresh();
    expect($job->status)->toBe(SimVendJob::STATUS_FAILED)
        ->and($job->failure_reason)->toBe('lease_expired')
        ->and($transaction->status)->toBe('fail')
        ->and((float) $user->fresh()->wallet_balance)->toBe(1500.0);

    // Device wakes up late claiming delivery: flagged, but no money moves.
    $late = signedSimSettleAck($this, $job, ['result' => 'executed']);
    expect($late->getStatusCode())->toBe(409)
        ->and((float) $user->fresh()->wallet_balance)->toBe(1500.0)
        ->and($transaction->refresh()->status)->toBe('fail');
});

test('the sweeper refunds pending jobs nobody claimed within the TTL', function () {
    [$user, $transaction, $job] = makeSimSettleWorld();
    // forceFill: created_at is not mass-assignable, and the sweep query
    // needs the row to look genuinely old.
    $job->forceFill([
        'status' => SimVendJob::STATUS_PENDING,
        'sim_device_id' => null,
        'sim_id' => null,
        'claimed_at' => null,
        'lease_expires_at' => null,
        'created_at' => now()->subSeconds((int) config('simvending.pending_ttl') + 60),
    ])->save();

    $this->artisan('sim:expire-jobs')->assertExitCode(0);

    expect($job->refresh()->status)->toBe(SimVendJob::STATUS_FAILED)
        ->and($job->failure_reason)->toBe('unclaimed_ttl_expired')
        ->and($transaction->refresh()->status)->toBe('fail')
        ->and((float) $user->fresh()->wallet_balance)->toBe(1500.0);
});

test('the sweeper leaves live jobs alone', function () {
    [$user, $transaction, $job] = makeSimSettleWorld();

    $this->artisan('sim:expire-jobs')->assertExitCode(0);

    expect($job->refresh()->status)->toBe(SimVendJob::STATUS_CLAIMED)
        ->and($transaction->refresh()->status)->toBe('pending')
        ->and((float) $user->fresh()->wallet_balance)->toBe(1000.0);
});
