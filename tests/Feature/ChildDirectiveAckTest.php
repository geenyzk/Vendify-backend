<?php

use App\Models\ChildDirective;
use App\Models\ChildInstance;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The full app migration set can't run on the sqlite test connection, so
 * these tests build only the two tables this channel needs. That keeps the
 * one regression that matters here runnable anywhere: ack() must receive the
 * directive {id} route param, not the {slug} — a positional mixup that once
 * 404'd every ack and left directives eternally pending (re-executed by the
 * child every poll).
 */

const CHILD_ACK_SECRET = 'test-secret';

function createChildDirectiveTables(): void
{
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

function makeChildInstanceWithDirective(string $type = 'message'): array
{
    $instance = ChildInstance::create([
        'name' => 'Ack Test Child',
        'slug' => 'ack-test-child',
        'base_url' => 'https://child.test',
        'shared_secret' => CHILD_ACK_SECRET,
        'status' => 'active',
    ]);

    $directive = ChildDirective::create([
        'child_instance_id' => $instance->id,
        'type' => $type,
        'payload' => ['text' => 'hello'],
        'status' => 'pending',
    ]);

    return [$instance, $directive];
}

function signedChildCall($test, string $method, string $uri, string $body = '')
{
    $timestamp = (string) time();

    return $test->call($method, $uri, [], [], [], [
        'HTTP_X_CHILD_INSTANCE' => 'ack-test-child',
        'HTTP_X_TIMESTAMP' => $timestamp,
        'HTTP_X_SIGNATURE' => hash_hmac('sha256', "{$timestamp}.{$body}", CHILD_ACK_SECRET),
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], $body);
}

beforeEach(function () {
    createChildDirectiveTables();
});

afterEach(function () {
    Schema::dropIfExists('child_directives');
    Schema::dropIfExists('child_instances');
});

test('ack resolves the directive by its id, not the slug route param', function () {
    [, $directive] = makeChildInstanceWithDirective();

    $body = json_encode(['result' => 'executed', 'note' => 'applied']);
    $response = signedChildCall($this, 'POST', "/api/child/ack-test-child/directives/{$directive->id}/ack", $body);

    expect($response->getStatusCode())->toBe(200);

    $directive->refresh();
    expect($directive->status)->toBe('executed')
        ->and($directive->executed_at)->not->toBeNull()
        ->and($directive->result_note)->toBe('applied');
});

test('ack records the failed result and note the child reports', function () {
    [, $directive] = makeChildInstanceWithDirective('update_settings');

    $body = json_encode(['result' => 'failed', 'note' => 'no applicable settings keys']);
    $response = signedChildCall($this, 'POST', "/api/child/ack-test-child/directives/{$directive->id}/ack", $body);

    expect($response->getStatusCode())->toBe(200);

    $directive->refresh();
    expect($directive->status)->toBe('failed')
        ->and($directive->executed_at)->toBeNull()
        ->and($directive->result_note)->toBe('no applicable settings keys');
});

test('a legacy empty-body ack still records delivered', function () {
    [, $directive] = makeChildInstanceWithDirective();

    $response = signedChildCall($this, 'POST', "/api/child/ack-test-child/directives/{$directive->id}/ack", '');

    expect($response->getStatusCode())->toBe(200);

    $directive->refresh();
    expect($directive->status)->toBe('delivered');
});

test('acked directives no longer appear in the pending pull feed', function () {
    [, $directive] = makeChildInstanceWithDirective();

    signedChildCall($this, 'POST', "/api/child/ack-test-child/directives/{$directive->id}/ack", json_encode(['result' => 'executed', 'note' => null]));

    $response = signedChildCall($this, 'GET', '/api/child/ack-test-child/directives');

    expect($response->getStatusCode())->toBe(200)
        ->and($response->json('data'))->toBe([]);
});
