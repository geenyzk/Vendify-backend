<?php

use App\Models\User;
use App\Notifications\VendifyVerifyEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

function unverifiedEmailUser(array $attributes = []): User
{
    return User::query()->create(array_merge([
        'username' => 'email-user',
        'fullname' => 'Email User',
        'email' => 'email-user@example.com',
        'phone' => '08000000001',
        'password' => 'password',
        'status' => 'active',
        'email_verified_at' => null,
    ], $attributes));
}

test('an authenticated user can resend the verification email', function () {
    Notification::fake();
    $user = unverifiedEmailUser();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/email/verification-notification')
        ->assertOk()
        ->assertJson([
            'success' => true,
            'message' => 'Verification email sent.',
        ]);

    Notification::assertSentTo($user, VendifyVerifyEmailNotification::class);
});

test('resending for an already verified address is idempotent', function () {
    Notification::fake();
    $user = unverifiedEmailUser(['email_verified_at' => now()]);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/email/verification-notification')
        ->assertOk()
        ->assertJson([
            'success' => true,
            'message' => 'Email already verified.',
        ]);

    Notification::assertNothingSent();
});

test('the verification email contains a valid signed link', function () {
    $user = unverifiedEmailUser();
    $mail = (new VendifyVerifyEmailNotification())->toMail($user);
    $verificationUrl = $mail->viewData['actionUrl'];

    $this->get($verificationUrl)->assertRedirectContains('/email-verified?verified=1');

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});
