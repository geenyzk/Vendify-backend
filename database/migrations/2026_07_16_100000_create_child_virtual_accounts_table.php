<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Virtual accounts the PARENT generates on behalf of an affiliate child's
 * customers. Money paid into these lands in the parent's payment provider; this
 * table is how an incoming funding webhook is mapped back to the child customer
 * it belongs to, so the parent can tell the child to credit them.
 *
 * The child never touches a payment provider — the parent aggregates all
 * funding, which is the whole point: the parent (affiliate owner) holds the
 * money and the child's wallet is a mirror it keeps in sync from credit events.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('child_virtual_accounts')) {
            return;
        }

        Schema::create('child_virtual_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('child_instance_id')->constrained('child_instances')->cascadeOnDelete();
            // The child's own identifier for its customer — echoed back on
            // credit events so the child can find the local customer to credit.
            $table->string('external_customer_id');
            // Optional link to the synced ChildCustomer row on the parent, when
            // one exists (it may not for a brand-new customer).
            $table->foreignId('child_customer_id')->nullable()->constrained('child_customers')->nullOnDelete();

            $table->string('provider')->default('paymentpoint');
            $table->string('account_number');
            $table->string('bank_name')->nullable();
            $table->string('account_name')->nullable();
            // Provider's own reference for the reserved account.
            $table->string('reference')->nullable();
            // Identity used at generation — the fallback key for matching an
            // incoming webhook when the payload doesn't echo the account number.
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->json('meta')->nullable();

            $table->timestamps();

            // One account per (child, customer, provider) — the request endpoint
            // is idempotent on this.
            $table->unique(['child_instance_id', 'external_customer_id', 'provider'], 'cva_child_customer_provider_unique');
            // Webhook resolution keys.
            $table->unique(['provider', 'account_number'], 'cva_provider_account_unique');
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('child_virtual_accounts');
    }
};
