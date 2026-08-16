<?php

use App\Models\ChildCustomer;
use App\Models\ChildInstance;
use App\Models\ChildTransaction;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Carbon::setTestNow('2026-08-16 21:00:00');

    Schema::create('roles', function (Blueprint $table) {
        $table->id(); $table->string('name'); $table->string('slug')->nullable();
        $table->boolean('is_staff')->default(false); $table->timestamps();
    });
    Schema::create('users', function (Blueprint $table) {
        $table->id(); $table->string('username')->nullable(); $table->string('email')->nullable();
        $table->string('password')->nullable(); $table->string('user_type')->nullable();
        $table->unsignedBigInteger('role_id')->nullable(); $table->string('status')->nullable();
        $table->string('referral_code')->nullable(); $table->rememberToken();
        $table->softDeletes(); $table->timestamps();
    });
    Schema::create('child_instances', function (Blueprint $table) {
        $table->id(); $table->string('name'); $table->string('slug')->unique();
        $table->text('shared_secret')->nullable(); $table->string('status')->default('active');
        $table->timestamp('last_seen_at')->nullable(); $table->string('health_status')->nullable();
        $table->text('config')->nullable(); $table->string('registration_code')->nullable();
        $table->timestamp('registration_code_expires_at')->nullable();
        $table->timestamp('registered_at')->nullable(); $table->timestamps();
    });
    Schema::create('child_customers', function (Blueprint $table) {
        $table->id(); $table->unsignedBigInteger('child_instance_id'); $table->string('external_id');
        $table->string('username')->nullable(); $table->string('email')->nullable();
        $table->string('phone')->nullable(); $table->decimal('wallet_balance', 15, 2)->default(0);
        $table->string('status')->nullable(); $table->unsignedBigInteger('migrated_to_user_id')->nullable();
        $table->timestamps();
    });
    Schema::create('child_transactions', function (Blueprint $table) {
        $table->id(); $table->unsignedBigInteger('child_instance_id');
        $table->unsignedBigInteger('child_customer_id')->nullable(); $table->string('external_id');
        $table->string('transaction_type')->nullable(); $table->decimal('amount', 15, 2)->default(0);
        $table->string('status')->nullable(); $table->timestamp('transacted_at')->nullable();
        $table->json('raw_payload')->nullable(); $table->timestamps();
    });

    $role = Role::create(['name' => 'Admin', 'slug' => 'admin', 'is_staff' => true]);
    $this->admin = User::create([
        'username' => 'activity-admin', 'email' => 'admin@example.test', 'password' => 'secret',
        'user_type' => 'admin', 'role_id' => $role->id, 'status' => 'active',
    ]);
    $this->instance = ChildInstance::create(['name' => 'MadiTel', 'slug' => 'maditel', 'status' => 'active']);
});

afterEach(function () {
    Carbon::setTestNow();
    foreach (['child_transactions', 'child_customers', 'child_instances', 'users', 'roles'] as $table) {
        Schema::dropIfExists($table);
    }
});

function activityCustomer(ChildInstance $instance, string $externalId, string $username, ?int $migratedId = null): ChildCustomer
{
    return ChildCustomer::create([
        'child_instance_id' => $instance->id, 'external_id' => $externalId,
        'username' => $username, 'email' => "{$username}@example.test", 'phone' => '08000000000',
        'wallet_balance' => 0, 'migrated_to_user_id' => $migratedId,
    ]);
}

function activityTransaction(ChildInstance $instance, ?ChildCustomer $customer, string $reference, string $when, string $status = 'success', string $type = 'data_subscription', int $amount = 600): ChildTransaction
{
    return ChildTransaction::create([
        'child_instance_id' => $instance->id, 'child_customer_id' => $customer?->id,
        'external_id' => $reference, 'transaction_type' => $type, 'amount' => $amount,
        'status' => $status, 'transacted_at' => $when,
    ]);
}

test('recent activity returns one customer with their latest successful service purchase', function () {
    $older = activityCustomer($this->instance, 'legacy-1', 'older');
    $newer = activityCustomer($this->instance, 'legacy-2', 'newer', $this->admin->id);
    activityTransaction($this->instance, $older, 'tx-1', '2026-08-16 18:00:00', amount: 350);
    activityTransaction($this->instance, $newer, 'tx-2', '2026-08-16 19:00:00', type: 'airtime_recharge', amount: 1000);
    activityTransaction($this->instance, $newer, 'tx-3', '2026-08-16 20:00:00', amount: 600);

    DB::enableQueryLog();
    $response = $this->actingAs($this->admin, 'sanctum')
        ->getJson("/api/admin/child-instances/{$this->instance->id}/customers/recent-activity?period=24h");

    $response->assertOk()->assertJsonCount(2, 'data.customers')
        ->assertJsonPath('data.customers.0.username', 'newer')
        ->assertJsonPath('data.customers.0.latest_transaction_id', activityTransactionId('tx-3'))
        ->assertJsonPath('data.customers.0.latest_transaction_type', 'data_subscription')
        ->assertJsonPath('data.customers.0.latest_transaction_amount', '600.00')
        ->assertJsonPath('data.customers.0.migrated_to_user_id', $this->admin->id)
        ->assertJsonPath('data.customers.1.username', 'older');

    expect(collect(DB::getQueryLog())->filter(
        fn ($entry) => str_contains($entry['query'], 'child_transactions as current_tx')
    ))->toHaveCount(1);
});

function activityTransactionId(string $reference): int
{
    return (int) ChildTransaction::where('external_id', $reference)->value('id');
}

test('period filters exclude old transactions and all uses application time without a cutoff', function (string $period, array $expected) {
    $today = activityCustomer($this->instance, 'today', 'today');
    $week = activityCustomer($this->instance, 'week', 'week');
    $month = activityCustomer($this->instance, 'month', 'month');
    $old = activityCustomer($this->instance, 'old', 'old');
    activityTransaction($this->instance, $today, 'today-tx', '2026-08-16 20:30:00');
    activityTransaction($this->instance, $week, 'week-tx', '2026-08-13 20:30:00');
    activityTransaction($this->instance, $month, 'month-tx', '2026-07-25 20:30:00');
    activityTransaction($this->instance, $old, 'old-tx', '2026-01-01 00:00:00');

    $response = $this->actingAs($this->admin, 'sanctum')
        ->getJson("/api/admin/child-instances/{$this->instance->id}/customers/recent-activity?period={$period}")
        ->assertOk();

    expect(collect($response->json('data.customers'))->pluck('username')->all())->toBe($expected);
})->with([
    ['24h', ['today']],
    ['7d', ['today', 'week']],
    ['30d', ['today', 'week', 'month']],
    ['all', ['today', 'week', 'month', 'old']],
]);

test('failed internal and unresolved transactions never mark a customer active', function () {
    $customer = activityCustomer($this->instance, 'legacy-1', 'customer');
    activityTransaction($this->instance, $customer, 'failed', '2026-08-16 20:50:00', status: 'failed');
    activityTransaction($this->instance, $customer, 'funding', '2026-08-16 20:40:00', type: 'wallet_funding');
    activityTransaction($this->instance, null, 'unresolved', '2026-08-16 20:30:00');

    $this->actingAs($this->admin, 'sanctum')
        ->getJson("/api/admin/child-instances/{$this->instance->id}/customers/recent-activity")
        ->assertOk()->assertJsonCount(0, 'data.customers')
        ->assertJsonPath('data.qualifying_statuses', ['success', 'successful', 'completed']);
});
