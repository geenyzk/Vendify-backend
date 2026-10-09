<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Schema::create('users', function (Blueprint $table) {
        $table->id();
    });
    Schema::create('providers', function (Blueprint $table) {
        $table->id();
        $table->string('name');
    });
    Schema::create('banks', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->string('provider')->nullable();
        $table->string('status')->nullable();
        $table->string('bank_account')->nullable();
        $table->string('bank_name')->nullable();
    });
});

afterEach(function () {
    Schema::dropIfExists('payment_point_provisionings');
    Schema::dropIfExists('banks');
    Schema::dropIfExists('providers');
    Schema::dropIfExists('users');
});

it('backfills only evidenced unresolved users and preserves an existing account', function () {
    DB::table('users')->insert([
        ['id' => 435],
        ['id' => 436],
        ['id' => 437],
        ['id' => 438],
        ['id' => 439],
    ]);
    DB::table('providers')->insert(['id' => 10, 'name' => 'PaymentPoint']);
    DB::table('banks')->insert([
        'user_id' => 438,
        'provider' => 'payment point',
        'status' => 'active',
        'bank_account' => '6698059290',
        'bank_name' => 'PalmPay',
    ]);

    $migration = require database_path('migrations/2026_10_09_120000_create_payment_point_provisionings_table.php');
    $migration->up();

    expect(DB::table('payment_point_provisionings')->pluck('user_id')->all())
        ->toBe([435, 436, 437])
        ->and(DB::table('banks')->where('user_id', 438)->value('bank_account'))
        ->toBe('6698059290');
});
