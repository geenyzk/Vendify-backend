<?php

use App\Models\DataPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

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
        // Legacy scalar entries remain exact selling prices; structured fiat
        // entries are the modern fixed-markup form tested separately.
        'pricing' => ['user' => 350],
    ]);

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/table/data_plans?view=list')
        ->assertOk()
        ->assertJsonPath('data.0.plan', '1GB')
        ->assertJsonPath('data.0.status', 'active')
        ->assertJsonPath('data.0.price', 350)
        ->assertJsonPath('data.0.price_ngn', '₦350.00');

    // Price is cheap to compute (a JSON column read + at most one batched
    // provider-pivot query for the whole page) and is now part of the
    // compact shape. The heavier fields it never needed stay excluded.
    expect($response->json('data.0'))->not->toHaveKeys([
        'pricing',
        'provider',
        'fallback_provider',
    ]);
});

test('the compact data plan list resolves price without an N+1 per row', function () {
    $user = performanceUser();

    // Percentage-type pricing is the expensive path: resolving it calls
    // DataPlan::resolveCostPrice(), which reads the providers() pivot — the
    // exact query that used to run once per row before providers:id was
    // eager-loaded for the compact list too.
    for ($i = 0; $i < 15; $i++) {
        DataPlan::query()->create([
            'network' => 'MTN',
            'plan_name' => (string) $i,
            'plan_size' => 'GB',
            'plan_type' => 'SME',
            'validity' => '30 days',
            'active' => true,
            'is_draft' => false,
            'pricing' => ['user' => ['type' => 'percentage', 'value' => 10]],
        ]);
    }

    DB::enableQueryLog();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/table/data_plans?view=list')
        ->assertOk();

    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Not an exact count (auth/session queries vary) — the point is that it
    // doesn't scale with the 15 rows, which an unbatched per-row provider
    // lookup would.
    expect($queryCount)->toBeLessThan(15);
});

test('global response diagnostics do not rewrite json bodies', function () {
    $response = $this->getJson('/api/branding')
        ->assertOk()
        ->assertHeader('X-Request-Id');

    expect($response->json())->not->toHaveKey('meta');
});
