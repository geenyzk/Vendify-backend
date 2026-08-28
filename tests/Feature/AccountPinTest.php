<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

function accountPinUser(array $attributes = []): User
{
    return User::query()->create(array_merge([
        'username' => 'pin-user',
        'fullname' => 'PIN User',
        'email' => 'pin@example.com',
        'phone' => '08000000000',
        'password' => 'password',
        'status' => 'active',
    ], $attributes));
}

test('a user without a pin can create one without a current pin', function () {
    $user = accountPinUser();

    $this->actingAs($user, 'sanctum')
        ->putJson('/api/account/pin', [
            'pin' => '1234',
            'pin_confirmation' => '1234',
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    expect(Hash::check('1234', $user->fresh()->pin))->toBeTrue();
});

test('a duplicate initial pin request is idempotent', function () {
    $user = accountPinUser(['pin' => '1234']);

    $this->actingAs($user, 'sanctum')
        ->putJson('/api/account/pin', [
            'pin' => '1234',
            'pin_confirmation' => '1234',
        ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.user.has_pin', true);
});

test('changing an existing pin still requires and verifies the current pin', function () {
    $user = accountPinUser(['pin' => '1234']);

    $this->actingAs($user, 'sanctum')
        ->putJson('/api/account/pin', [
            'pin' => '5678',
            'pin_confirmation' => '5678',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('current_pin');

    $this->actingAs($user, 'sanctum')
        ->putJson('/api/account/pin', [
            'currentPin' => '1234',
            'pin' => '5678',
            'pinConfirmation' => '5678',
        ])
        ->assertOk();

    expect(Hash::check('5678', $user->fresh()->pin))->toBeTrue();
});

test('a forgotten pin can be reset without weakening the normal change route', function () {
    $user = accountPinUser(['pin' => '1234']);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/account/pin/reset', [
            'pin' => '9876',
            'pin_confirmation' => '9876',
        ])
        ->assertOk()
        ->assertJsonPath('data.user.has_pin', true);

    expect(Hash::check('9876', $user->fresh()->pin))->toBeTrue();
});

test('a forgotten pin reset requires matching four digit confirmation', function () {
    $user = accountPinUser(['pin' => '1234']);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/account/pin/reset', [
            'pin' => '9876',
            'pin_confirmation' => '9875',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('pin');

    expect(Hash::check('1234', $user->fresh()->pin))->toBeTrue();
});
