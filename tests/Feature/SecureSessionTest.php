<?php

use App\Models\AuthSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

function secureSessionUser(array $attributes = []): User
{
    return User::query()->create(array_merge([
        'username' => 'session-user',
        'fullname' => 'Session User',
        'email' => 'session@example.com',
        'phone' => '08012345678',
        'password' => Hash::make('password123'),
        'status' => User::STATUS_ACTIVE,
        'is_active' => true,
    ], $attributes));
}

function webLoginPayload(bool $remember = false): array
{
    return [
        'login' => 'session@example.com',
        'password' => 'password123',
        'remember' => $remember,
        'client_type' => 'web',
    ];
}

test('web login rotates into a tracked HttpOnly session without exposing a bearer token', function () {
    secureSessionUser();

    $response = $this->withHeader('Origin', 'http://localhost:5173')
        ->postJson('/api/login', webLoginPayload());

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonMissingPath('data.token')
        ->assertJsonMissingPath('data.access_token')
        ->assertJsonPath('data.session.channel', 'web');

    expect(AuthSession::query()->where('channel', 'web')->count())->toBe(1);
    expect(AuthSession::first()->idle_expires_at->between(now()->addMinutes(29), now()->addMinutes(31)))->toBeTrue();
});

test('remember me extends only the absolute ceiling while retaining the 30 minute idle limit', function () {
    secureSessionUser();

    $this->withHeader('Origin', 'http://localhost:5173')
        ->postJson('/api/login', webLoginPayload(true))
        ->assertOk();

    $session = AuthSession::firstOrFail();
    expect($session->idle_expires_at->between(now()->addMinutes(29), now()->addMinutes(31)))->toBeTrue();
    expect($session->absolute_expires_at->between(now()->addDays(29), now()->addDays(31)))->toBeTrue();
});

test('an inactive web session is revoked and rejected', function () {
    secureSessionUser();

    $this->withHeader('Origin', 'http://localhost:5173')
        ->postJson('/api/login', webLoginPayload())
        ->assertOk();

    AuthSession::firstOrFail()->forceFill(['idle_expires_at' => now()->subSecond()])->save();

    $this->withHeader('Origin', 'http://localhost:5173')
        ->getJson('/api/user')
        ->assertUnauthorized()
        ->assertJsonPath('code', 'SESSION_EXPIRED');

    expect(AuthSession::first()->revocation_reason)->toBe('session_expired');
});

test('mobile refresh tokens rotate and reuse revokes the full device session', function () {
    secureSessionUser();

    $login = $this->withHeaders([
        'X-Client-Platform' => 'app',
        'X-Device-Id' => 'test-device',
    ])->postJson('/api/login', [
        ...webLoginPayload(),
        'client_type' => 'mobile',
    ])->assertOk();

    $oldRefresh = $login->json('data.refresh_token');
    expect($login->json('data.access_token'))->toBeString();
    expect($oldRefresh)->toBeString();

    $rotated = $this->withHeaders(['X-Client-Platform' => 'app', 'X-Device-Id' => 'test-device'])
        ->postJson('/api/auth/refresh', ['refresh_token' => $oldRefresh])
        ->assertOk();

    expect($rotated->json('data.refresh_token'))->not->toBe($oldRefresh);

    $this->withHeaders(['X-Client-Platform' => 'app', 'X-Device-Id' => 'test-device'])
        ->postJson('/api/auth/refresh', ['refresh_token' => $oldRefresh])
        ->assertUnauthorized();

    $session = AuthSession::firstOrFail()->fresh();
    expect($session->revoked_at)->not->toBeNull();
    expect($session->revocation_reason)->toBe('refresh_token_reuse');
    expect($session->refreshTokens()->whereNull('revoked_at')->count())->toBe(0);
});

test('changing a password invalidates every active device session', function () {
    $user = secureSessionUser();
    $this->withHeader('Origin', 'http://localhost:5173')
        ->postJson('/api/login', webLoginPayload())
        ->assertOk();
    AuthSession::create([
        'user_id' => $user->id,
        'channel' => 'mobile',
        'last_active_at' => now(),
        'absolute_expires_at' => now()->addDays(30),
        'reauthenticated_at' => now(),
    ]);

    $this->withHeader('Origin', 'http://localhost:5173')
        ->putJson('/api/account/password', [
            'current_password' => 'password123',
            'password' => 'new-password123',
            'password_confirmation' => 'new-password123',
        ])
        ->assertOk();

    expect(AuthSession::whereNull('revoked_at')->count())->toBe(0);
});
