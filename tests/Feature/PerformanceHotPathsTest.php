<?php

use App\Models\DataPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function performanceUser(): User
{
    return User::query()->create([
        'username' => 'performance-user',
        'fullname' => 'Performance User',
        'email' => 'performance@example.com',
        'phone' => '08000000009',
        'password' => 'password',
        'status' => 'active',
    ]);
}

test('the admin data plan list uses the compact representation', function () {
    $user = performanceUser();
    DataPlan::query()->create([
        'network' => 'MTN',
        'plan_name' => '1',
        'plan_size' => 'GB',
        'plan_type' => 'SME',
        'validity' => '30 days',
        'active' => true,
        'is_draft' => false,
        'pricing' => ['user' => ['type' => 'fiat', 'value' => 350]],
    ]);

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/table/data_plans?view=list')
        ->assertOk()
        ->assertJsonPath('data.0.plan', '1GB')
        ->assertJsonPath('data.0.status', 'active');

    expect($response->json('data.0'))->not->toHaveKeys([
        'pricing',
        'provider',
        'fallback_provider',
        'price',
        'price_ngn',
    ]);
});

test('global response diagnostics do not rewrite json bodies', function () {
    $response = $this->getJson('/api/branding')
        ->assertOk()
        ->assertHeader('X-Request-Id');

    expect($response->json())->not->toHaveKey('meta');
});
