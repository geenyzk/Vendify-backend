<?php

use App\Models\ChildDirective;
use App\Models\ChildInstance;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Route-tunneling endpoints (ChildTunnelController) + directive
 * retract/delete. Same pattern as ChildDirectiveAckTest: the full migration
 * set can't run on the sqlite test connection, so only the tables these
 * flows touch are created here.
 */

function createTunnelTables(): void
{
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('username')->nullable();
        $table->string('fullname')->nullable();
        $table->string('email')->nullable();
        $table->string('phone')->nullable();
        $table->string('password')->nullable();
        $table->string('pin')->nullable();
        $table->string('user_type')->nullable();
        $table->unsignedBigInteger('role_id')->nullable();
        $table->decimal('wallet_balance', 12, 2)->default(0);
        $table->boolean('is_active')->default(true);
        $table->boolean('is_verified')->default(true);
        $table->string('status')->nullable();
        $table->string('referral_code')->nullable();
        $table->string('referred_by')->nullable();
        $table->decimal('referral_balance', 12, 2)->default(0);
        $table->decimal('total_referral_earnings', 12, 2)->default(0);
        $table->timestamp('last_login_at')->nullable();
        $table->timestamp('email_verified_at')->nullable();
        $table->rememberToken();
        $table->softDeletes();
        $table->timestamps();
    });

    Schema::create('child_tunnel_requests', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->string('request_id');
        $table->string('service', 20);
        $table->string('status', 20);
        $table->string('response_message', 500)->nullable();
        $table->timestamps();
        $table->unique(['user_id', 'request_id']);
    });

    Schema::create('data_plans', function (Blueprint $table) {
        $table->id();
        $table->string('network')->nullable();
        $table->string('plan_type')->nullable();
        $table->string('plan_name')->nullable();
        $table->timestamps();
    });

    Schema::create('child_instances', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('slug')->unique();
        $table->string('base_url')->nullable();
        $table->text('shared_secret')->nullable();
        $table->string('status')->default('active');
        $table->timestamp('last_seen_at')->nullable();
        $table->string('health_status')->nullable();
        $table->text('config')->nullable();
        $table->string('registration_code')->nullable();
        $table->timestamp('registration_code_expires_at')->nullable();
        $table->timestamp('registered_at')->nullable();
        $table->timestamps();
    });

    Schema::create('child_directives', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('child_instance_id');
        $table->string('type');
        $table->text('payload')->nullable();
        $table->string('status')->default('pending');
        $table->timestamp('delivered_at')->nullable();
        $table->timestamp('executed_at')->nullable();
        $table->string('result_note', 1000)->nullable();
        $table->timestamps();
    });
}

function tunnelUser(string $type = 'customer'): User
{
    return User::create([
        'username' => 'tunnel-' . $type,
        'email' => "tunnel-{$type}@example.test",
        'password' => 'secret-pass',
        'user_type' => $type,
        'wallet_balance' => 5000,
    ]);
}

beforeEach(function () {
    createTunnelTables();
});

afterEach(function () {
    foreach (['child_directives', 'child_instances', 'data_plans', 'child_tunnel_requests', 'users'] as $table) {
        Schema::dropIfExists($table);
    }
});

// ── adex-protocol auth handshake ────────────────────────────────────────

test('POST /api/user issues an AccessToken for valid Basic credentials', function () {
    $user = tunnelUser();

    $response = $this->call('POST', '/api/user', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('tunnel-customer:secret-pass'),
        'HTTP_ACCEPT' => 'application/json',
    ]);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->json('AccessToken'))->not->toBeNull()
        ->and($response->json('username'))->toBe($user->username)
        ->and((float) $response->json('balance'))->toBe(5000.0);
});

test('POST /api/user rejects a wrong password with 401', function () {
    tunnelUser();

    $response = $this->call('POST', '/api/user', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('tunnel-customer:wrong'),
        'HTTP_ACCEPT' => 'application/json',
    ]);

    expect($response->getStatusCode())->toBe(401);
});

// ── vending ─────────────────────────────────────────────────────────────

function tunnelToken($test): string
{
    $response = $test->call('POST', '/api/user', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('tunnel-customer:secret-pass'),
        'HTTP_ACCEPT' => 'application/json',
    ]);

    return $response->json('AccessToken');
}

test('vend endpoints reject a missing or garbage token', function () {
    tunnelUser();

    $response = $this->postJson('/api/data', ['data_plan' => 1, 'phone' => '08012345678']);
    expect($response->getStatusCode())->toBe(401);

    $response = $this->call('POST', '/api/data', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Token not-a-real-token',
        'HTTP_ACCEPT' => 'application/json',
    ], json_encode(['data_plan' => 1, 'phone' => '08012345678']));
    expect($response->getStatusCode())->toBe(401);
});

test('an unknown data plan fails in-protocol (status fail), not as an HTTP error', function () {
    tunnelUser();
    $token = tunnelToken($this);

    $response = $this->call('POST', '/api/data', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Token ' . $token,
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/json',
    ], json_encode(['data_plan' => 999, 'phone' => '08012345678', 'request-id' => 'RQ1']));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->json('status'))->toBe('fail');
});

test('a replayed request-id returns the stored outcome instead of vending again', function () {
    $user = tunnelUser();
    $token = tunnelToken($this);

    DB::table('child_tunnel_requests')->insert([
        'user_id' => $user->id,
        'request_id' => 'RQ-DONE',
        'service' => 'data',
        'status' => 'success',
        'response_message' => 'delivered on first attempt',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // No data_plan sent at all — the replay must win before any validation
    // or catalog lookup, because the original outcome is already settled.
    $response = $this->call('POST', '/api/data', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Token ' . $token,
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/json',
    ], json_encode(['request-id' => 'RQ-DONE']));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->json('status'))->toBe('success')
        ->and($response->json('response'))->toBe('delivered on first attempt');
});

test('topup rejects an unknown network in-protocol', function () {
    tunnelUser();
    $token = tunnelToken($this);

    $response = $this->call('POST', '/api/topup', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Token ' . $token,
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/json',
    ], json_encode(['network' => '99', 'phone' => '08012345678', 'amount' => 100]));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->json('status'))->toBe('fail');
});

// ── directive retract/delete ────────────────────────────────────────────

function directiveFor(ChildInstance $instance, string $status = 'pending'): ChildDirective
{
    return ChildDirective::create([
        'child_instance_id' => $instance->id,
        'type' => 'message',
        'payload' => ['text' => 'hi'],
        'status' => $status,
    ]);
}

test('an admin can retract a pending directive', function () {
    $admin = tunnelUser('admin');
    $instance = ChildInstance::create([
        'name' => 'Del Child', 'slug' => 'del-child', 'shared_secret' => 's', 'status' => 'active',
    ]);
    $directive = directiveFor($instance);

    $response = $this->actingAs($admin, 'sanctum')
        ->deleteJson("/api/admin/child-instances/{$instance->id}/directives/{$directive->id}");

    expect($response->getStatusCode())->toBe(200)
        ->and($response->json('data.retracted'))->toBeTrue()
        ->and(ChildDirective::find($directive->id))->toBeNull();
});

test('deleting an already-acked directive reports retracted=false', function () {
    $admin = tunnelUser('admin');
    $instance = ChildInstance::create([
        'name' => 'Del Child', 'slug' => 'del-child', 'shared_secret' => 's', 'status' => 'active',
    ]);
    $directive = directiveFor($instance, 'executed');

    $response = $this->actingAs($admin, 'sanctum')
        ->deleteJson("/api/admin/child-instances/{$instance->id}/directives/{$directive->id}");

    expect($response->getStatusCode())->toBe(200)
        ->and($response->json('data.retracted'))->toBeFalse()
        ->and(ChildDirective::find($directive->id))->toBeNull();
});

test('a non-admin cannot delete directives', function () {
    $customer = tunnelUser('customer');
    $instance = ChildInstance::create([
        'name' => 'Del Child', 'slug' => 'del-child', 'shared_secret' => 's', 'status' => 'active',
    ]);
    $directive = directiveFor($instance);

    $response = $this->actingAs($customer, 'sanctum')
        ->deleteJson("/api/admin/child-instances/{$instance->id}/directives/{$directive->id}");

    expect($response->getStatusCode())->toBe(403)
        ->and(ChildDirective::find($directive->id))->not->toBeNull();
});
