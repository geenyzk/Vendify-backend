<?php

use App\Models\ChildCustomer;
use App\Models\ChildCustomerMessage;
use App\Models\ChildDirective;
use App\Models\ChildInstance;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;

function createMigrationTestTables(): void
{
    Schema::create('roles', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('slug')->nullable();
        $table->boolean('is_default')->default(false);
        $table->timestamps();
    });

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
        $table->rememberToken();
        $table->softDeletes();
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

    Schema::create('child_customers', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('child_instance_id');
        $table->string('external_id');
        $table->string('username')->nullable();
        $table->string('email')->nullable();
        $table->string('phone')->nullable();
        $table->decimal('wallet_balance', 15, 2)->default(0);
        $table->string('status')->nullable();
        $table->unsignedBigInteger('migrated_to_user_id')->nullable();
        $table->timestamps();
        $table->unique(['child_instance_id', 'external_id']);
    });

    Schema::create('child_directives', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('child_instance_id');
        $table->string('type');
        $table->json('payload')->nullable();
        $table->string('status')->default('pending');
        $table->timestamp('delivered_at')->nullable();
        $table->timestamp('executed_at')->nullable();
        $table->string('result_note', 1000)->nullable();
        $table->timestamps();
    });

    Schema::create('child_customer_messages', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('child_customer_id');
        $table->unsignedBigInteger('sent_by')->nullable();
        $table->string('subject');
        $table->text('body');
        $table->timestamps();
    });
}

beforeEach(function () {
    createMigrationTestTables();
    Notification::fake();
    Password::shouldReceive('createToken')->andReturn('test-token');

    Role::create([
        'name' => 'Basic',
        'slug' => 'basic',
        'is_default' => true,
    ]);
});

afterEach(function () {
    foreach (['child_customer_messages', 'child_directives', 'child_customers', 'child_instances', 'users', 'roles'] as $table) {
        Schema::dropIfExists($table);
    }
});

test('bulk migrating selected affiliate customers creates parent accounts and directives', function () {
    $admin = User::create([
        'username' => 'admin-migrator',
        'email' => 'admin@example.test',
        'password' => 'secret-pass',
        'user_type' => 'admin',
        'role_id' => Role::first()->id,
        'status' => 'active',
    ]);

    $instance = ChildInstance::create([
        'name' => 'Affiliate One',
        'slug' => 'affiliate-one',
        'status' => 'active',
    ]);

    $first = ChildCustomer::create([
        'child_instance_id' => $instance->id,
        'external_id' => 'child-1',
        'username' => 'alpha',
        'email' => 'alpha@example.test',
        'phone' => '+2348000000001',
        'wallet_balance' => 0,
    ]);

    $second = ChildCustomer::create([
        'child_instance_id' => $instance->id,
        'external_id' => 'child-2',
        'username' => 'beta',
        'email' => 'beta@example.test',
        'phone' => '+2348000000002',
        'wallet_balance' => 0,
    ]);

    $response = $this->actingAs($admin, 'sanctum')
        ->postJson("/api/admin/child-instances/{$instance->id}/customers/bulk-migrate", [
            'customer_ids' => [$first->id, $second->id],
            'target_url' => 'https://parent.example.test',
        ]);

    $response->assertOk();
    $response->assertJsonCount(2, 'data');

    $first->refresh();
    $second->refresh();

    expect($first->migrated_to_user_id)->not->toBeNull()
        ->and($second->migrated_to_user_id)->not->toBeNull()
        ->and(ChildDirective::where('child_instance_id', $instance->id)->count())->toBe(2)
        ->and(User::where('email', 'alpha@example.test')->exists())->toBeTrue()
        ->and(User::where('email', 'beta@example.test')->exists())->toBeTrue();
});

test('email and migrate sends the email after migration succeeds', function () {
    Mail::fake();

    $admin = User::create([
        'username' => 'admin-email-migrator',
        'email' => 'admin-email@example.test',
        'password' => 'secret-pass',
        'user_type' => 'admin',
        'role_id' => Role::first()->id,
        'status' => 'active',
    ]);
    $parent = User::create([
        'username' => 'migration-target',
        'email' => 'target@example.test',
        'phone' => '+2348000000010',
        'password' => 'secret-pass',
        'role_id' => Role::first()->id,
        'status' => 'active',
    ]);
    $instance = ChildInstance::create([
        'name' => 'Affiliate Email',
        'slug' => 'affiliate-email',
        'status' => 'active',
    ]);
    $customer = ChildCustomer::create([
        'child_instance_id' => $instance->id,
        'external_id' => 'email-child-1',
        'username' => 'target',
        'email' => $parent->email,
        'phone' => $parent->phone,
        'wallet_balance' => 0,
    ]);

    $response = $this->actingAs($admin, 'sanctum')
        ->postJson("/api/admin/child-instances/{$instance->id}/customers/email-and-migrate", [
            'customer_ids' => [$customer->id],
            'subject' => 'Migration complete',
            'body' => 'Hello {{ user.username }}',
        ]);

    $response->assertOk()
        ->assertJsonPath('data.0.success', true)
        ->assertJsonPath('data.0.email_sent', true);
    expect($customer->fresh()->migrated_to_user_id)->toBe($parent->id);
    Mail::assertSent(\App\Mail\AdminNotificationMail::class, function ($mail) use ($customer) {
        return $mail->hasTo($customer->email)
            && $customer->fresh()->migrated_to_user_id !== null;
    });
});

test('email and migrate still emails customers that were already migrated', function () {
    Mail::fake();

    $admin = User::create([
        'username' => 'admin-remailer',
        'email' => 'admin-remailer@example.test',
        'password' => 'secret-pass',
        'user_type' => 'admin',
        'role_id' => Role::first()->id,
        'status' => 'active',
    ]);
    $parent = User::create([
        'username' => 'already-parent',
        'email' => 'already@example.test',
        'phone' => '+2348000000020',
        'password' => 'secret-pass',
        'role_id' => Role::first()->id,
        'status' => 'active',
    ]);
    $instance = ChildInstance::create([
        'name' => 'Affiliate Remail',
        'slug' => 'affiliate-remail',
        'status' => 'active',
    ]);
    $customer = ChildCustomer::create([
        'child_instance_id' => $instance->id,
        'external_id' => 'email-child-2',
        'username' => 'already',
        'email' => $parent->email,
        'phone' => $parent->phone,
        'wallet_balance' => 0,
        'migrated_to_user_id' => $parent->id,
    ]);

    $response = $this->actingAs($admin, 'sanctum')
        ->postJson("/api/admin/child-instances/{$instance->id}/customers/email-and-migrate", [
            'customer_ids' => [$customer->id],
            'subject' => 'Important update',
            'body' => 'Hello {{ user.username }}',
        ]);

    $response->assertOk()
        ->assertJsonPath('data.0.success', true)
        ->assertJsonPath('data.0.email_sent', true)
        ->assertJsonPath('data.0.message', 'Email sent; customer was already migrated');
    Mail::assertSent(\App\Mail\AdminNotificationMail::class, fn ($mail) => $mail->hasTo($customer->email));
});

test('bulk customer email is a separate action for migrated customers', function () {
    Mail::fake();

    $admin = User::create([
        'username' => 'admin-bulk-email',
        'email' => 'admin-bulk-email@example.test',
        'password' => 'secret-pass',
        'user_type' => 'admin',
        'role_id' => Role::first()->id,
        'status' => 'active',
    ]);
    $parent = User::create([
        'username' => 'bulk-email-parent',
        'email' => 'bulk-email@example.test',
        'phone' => '+2348000000030',
        'password' => 'secret-pass',
        'role_id' => Role::first()->id,
        'status' => 'active',
    ]);
    $instance = ChildInstance::create([
        'name' => 'Affiliate Bulk Email',
        'slug' => 'affiliate-bulk-email',
        'status' => 'active',
    ]);
    $customer = ChildCustomer::create([
        'child_instance_id' => $instance->id,
        'external_id' => 'bulk-email-child',
        'username' => 'bulk-recipient',
        'email' => $parent->email,
        'phone' => $parent->phone,
        'wallet_balance' => 0,
        'migrated_to_user_id' => $parent->id,
    ]);

    $response = $this->actingAs($admin, 'sanctum')
        ->postJson("/api/admin/child-instances/{$instance->id}/customers/messages", [
            'customer_ids' => [$customer->id],
            'subject' => 'Welcome {{ user.username }}',
            'body' => 'Your migration is complete.',
        ]);

    $response->assertOk()
        ->assertJsonPath('data.0.success', true)
        ->assertJsonPath('data.0.email_sent', true);
    Mail::assertSent(\App\Mail\AdminNotificationMail::class, fn ($mail) => $mail->hasTo($customer->email));
    expect(ChildCustomerMessage::where('child_customer_id', $customer->id)->exists())->toBeTrue();
});
