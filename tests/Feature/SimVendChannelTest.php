<?php

use App\Models\Sim;
use App\Models\SimDevice;
use App\Models\SimVendJob;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Device-facing half of SIM vending: registration bootstrap, heartbeat
 * stock reporting, and the claim protocol (network matching, stock
 * eligibility, leasing). Settlement (ack/expiry money movement) lives in
 * SimVendSettleTest. Tables are self-created — the full migration set
 * doesn't run on the sqlite test connection.
 */

const SIM_CHANNEL_SECRET = 'sim-test-secret';

function createSimChannelTables(): void
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
}

function dropSimChannelTables(): void
{
    Schema::dropIfExists('sim_vend_jobs');
    Schema::dropIfExists('sims');
    Schema::dropIfExists('sim_devices');
}

function makeSimDevice(array $simAttrs = [], array $deviceAttrs = []): SimDevice
{
    $device = SimDevice::create(array_merge([
        'name' => 'Test Phone',
        'slug' => 'sim-test-device',
        'shared_secret' => SIM_CHANNEL_SECRET,
        'status' => 'active',
        'last_seen_at' => now(),
        'registered_at' => now(),
    ], $deviceAttrs));

    Sim::create(array_merge([
        'sim_device_id' => $device->id,
        'slot_index' => 0,
        'network' => 'mtn',
        'supports_airtime' => true,
        'airtime_balance' => 10000,
        'enabled' => true,
    ], $simAttrs));

    return $device;
}

function makeSimJob(array $overrides = []): SimVendJob
{
    return SimVendJob::create(array_merge([
        'transaction_reference' => 'SIMTX-' . uniqid(),
        'user_id' => 1,
        'service' => 'airtime',
        'network' => 'mtn',
        'phone' => '08012345678',
        'amount' => 500,
        'status' => SimVendJob::STATUS_PENDING,
    ], $overrides));
}

function signedSimCall($test, string $method, string $uri, string $body = '', string $slug = 'sim-test-device')
{
    $timestamp = (string) time();

    return $test->call($method, $uri, [], [], [], [
        'HTTP_X_SIM_DEVICE' => $slug,
        'HTTP_X_TIMESTAMP' => $timestamp,
        'HTTP_X_SIGNATURE' => hash_hmac('sha256', "{$timestamp}.{$body}", SIM_CHANNEL_SECRET),
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], $body);
}

beforeEach(function () {
    dropSimChannelTables();
    createSimChannelTables();
});

afterEach(function () {
    dropSimChannelTables();
});

// ─── Registration ────────────────────────────────────────────────────────────

test('a device exchanges its one-time code for a slug and secret exactly once', function () {
    $device = SimDevice::create([
        'name' => 'New Phone',
        'registration_code' => 'ABC1234567',
        'registration_code_expires_at' => now()->addDay(),
    ]);

    $response = $this->postJson('/api/sim/register', [
        'registration_code' => 'ABC1234567',
        'app_version' => '1.0.0',
        'sims' => [
            ['slot_index' => 0, 'network' => 'MTN', 'phone_number' => '08111111111'],
            ['slot_index' => 1, 'network' => 'glo'],
        ],
    ]);

    $response->assertStatus(200);
    expect($response->json('data.slug'))->not->toBeNull()
        ->and($response->json('data.shared_secret'))->not->toBeNull();

    $device->refresh();
    expect($device->registered_at)->not->toBeNull()
        ->and($device->registration_code)->toBeNull()
        ->and($device->sims()->count())->toBe(2)
        // Networks normalize to lowercase so job matching is case-stable.
        ->and($device->sims()->where('slot_index', 0)->first()->network)->toBe('mtn');

    // Second use of the same code must fail — it was nulled on success.
    $this->postJson('/api/sim/register', ['registration_code' => 'ABC1234567'])
        ->assertStatus(401);
});

test('an expired registration code is rejected', function () {
    SimDevice::create([
        'name' => 'Late Phone',
        'registration_code' => 'EXPIRED123',
        'registration_code_expires_at' => now()->subMinute(),
    ]);

    $this->postJson('/api/sim/register', ['registration_code' => 'EXPIRED123'])
        ->assertStatus(401);
});

// ─── Auth ────────────────────────────────────────────────────────────────────

test('the sim channel rejects unsigned and wrongly-signed requests', function () {
    makeSimDevice();

    // No headers at all.
    $this->postJson('/api/sim/sim-test-device/jobs/claim')->assertStatus(401);

    // Wrong secret.
    $timestamp = (string) time();
    $this->call('POST', '/api/sim/sim-test-device/jobs/claim', [], [], [], [
        'HTTP_X_SIM_DEVICE' => 'sim-test-device',
        'HTTP_X_TIMESTAMP' => $timestamp,
        'HTTP_X_SIGNATURE' => hash_hmac('sha256', "{$timestamp}.", 'wrong-secret'),
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], '')->assertStatus(401);
});

// ─── Heartbeat ───────────────────────────────────────────────────────────────

test('heartbeat updates sim balances and returns agent config', function () {
    $device = makeSimDevice();

    $body = json_encode([
        'app_version' => '1.2.0',
        'sims' => [
            ['slot_index' => 0, 'network' => 'mtn', 'airtime_balance' => 4321.5, 'data_balance_mb' => 2048],
        ],
    ]);

    $response = signedSimCall($this, 'POST', '/api/sim/sim-test-device/heartbeat', $body);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->json('data.config.poll_interval'))->toBe((int) config('simvending.poll_interval'));

    $sim = $device->sims()->first()->refresh();
    expect((float) $sim->airtime_balance)->toBe(4321.5)
        ->and((float) $sim->data_balance_mb)->toBe(2048.0)
        ->and($sim->balance_reported_at)->not->toBeNull();
});

// ─── Claim ───────────────────────────────────────────────────────────────────

test('claim leases the oldest pending job for a matching network', function () {
    $device = makeSimDevice();
    $first = makeSimJob();
    makeSimJob(); // younger job stays pending

    $response = signedSimCall($this, 'POST', '/api/sim/sim-test-device/jobs/claim', '{}');

    expect($response->getStatusCode())->toBe(200)
        ->and($response->json('data.jobs.0.id'))->toBe($first->id);

    $first->refresh();
    expect($first->status)->toBe(SimVendJob::STATUS_CLAIMED)
        ->and($first->sim_device_id)->toBe($device->id)
        ->and($first->attempts)->toBe(1)
        ->and($first->lease_expires_at)->not->toBeNull();

    // A claimed job is never handed out again.
    $again = signedSimCall($this, 'POST', '/api/sim/sim-test-device/jobs/claim', '{}');
    expect($again->json('data.jobs.1'))->toBeNull()
        ->and($again->json('data.jobs.0.id'))->not->toBe($first->id);
});

test('claim skips jobs for networks the device has no sim for', function () {
    makeSimDevice(); // mtn sim only
    makeSimJob(['network' => 'airtel']);

    $response = signedSimCall($this, 'POST', '/api/sim/sim-test-device/jobs/claim', '{}');

    expect($response->getStatusCode())->toBe(200)
        ->and($response->json('data.jobs'))->toBe([]);
});

test('claim skips jobs the sim cannot cover after the airtime reserve', function () {
    // Balance 500 can't cover a 500 vend + reserve headroom.
    makeSimDevice(['airtime_balance' => 500]);
    makeSimJob(['amount' => 500]);

    $response = signedSimCall($this, 'POST', '/api/sim/sim-test-device/jobs/claim', '{}');

    expect($response->json('data.jobs'))->toBe([]);
});

test('claim skips disabled sims', function () {
    makeSimDevice(['enabled' => false]);
    makeSimJob();

    $response = signedSimCall($this, 'POST', '/api/sim/sim-test-device/jobs/claim', '{}');

    expect($response->json('data.jobs'))->toBe([]);
});
