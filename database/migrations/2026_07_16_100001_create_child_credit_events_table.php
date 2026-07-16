<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outbox of "a child customer was funded" events. When money lands in the
 * parent's payment provider for a child virtual account, the parent records one
 * row here; the child polls, credits its local customer, and acks — the same
 * reliable pull/ack pattern as child_directives, so a child that is briefly
 * offline never loses a credit.
 *
 * `reference` is the funding transaction reference and is unique, so a provider
 * webhook retry can never enqueue (or pay out) the same funding twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('child_credit_events')) {
            return;
        }

        Schema::create('child_credit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('child_instance_id')->constrained('child_instances')->cascadeOnDelete();
            $table->foreignId('child_virtual_account_id')->nullable()->constrained('child_virtual_accounts')->nullOnDelete();

            // Who to credit on the child, echoed from the virtual account.
            $table->string('external_customer_id');

            // Amount to credit the child's customer (net of the payment
            // provider's own fee), plus the gross paid and the fee, for audit.
            $table->decimal('amount', 15, 2);
            $table->decimal('gross_amount', 15, 2)->default(0);
            $table->decimal('fee', 15, 2)->default(0);

            $table->string('provider')->default('paymentpoint');
            // Idempotency: the funding transaction reference. Unique so a webhook
            // retry updates rather than duplicates.
            $table->string('reference')->unique();

            // pending -> delivered (fetched) -> credited | failed (child ack)
            $table->string('status', 20)->default('pending');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('acked_at')->nullable();
            $table->string('result_note')->nullable();

            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['child_instance_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('child_credit_events');
    }
};
