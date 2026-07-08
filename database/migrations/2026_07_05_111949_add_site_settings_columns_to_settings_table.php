<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            // Transaction
            $table->string('invoice_prefix')->default('TXN-');
            $table->string('invoice_suffix')->nullable();

            // Notification (which events email the admin)
            $table->boolean('notify_admin_on_signup')->default(true);
            $table->boolean('notify_admin_on_funding')->default(true);
            $table->boolean('notify_admin_on_large_transaction')->default(true);
            $table->decimal('large_transaction_threshold', 15, 2)->default(50000);
            $table->boolean('notify_admin_on_failed_transaction')->default(false);

            // Email / mail provider (applied at runtime over config/mail.php —
            // left null means "use whatever is in .env", so this is opt-in)
            $table->string('mail_mailer')->nullable();
            $table->string('mail_host')->nullable();
            $table->string('mail_port')->nullable();
            $table->string('mail_username')->nullable();
            $table->string('mail_password')->nullable();
            $table->string('mail_encryption')->nullable();
            $table->string('mail_from_address')->nullable();
            $table->string('mail_from_name')->nullable();

            // Customer
            $table->boolean('registrations_open')->default(true);
            $table->decimal('signup_bonus_amount', 15, 2)->default(0);
            $table->decimal('min_wallet_funding_amount', 15, 2)->default(100);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn([
                'invoice_prefix', 'invoice_suffix',
                'notify_admin_on_signup', 'notify_admin_on_funding',
                'notify_admin_on_large_transaction', 'large_transaction_threshold',
                'notify_admin_on_failed_transaction',
                'mail_mailer', 'mail_host', 'mail_port', 'mail_username',
                'mail_password', 'mail_encryption', 'mail_from_address', 'mail_from_name',
                'registrations_open', 'signup_bonus_amount', 'min_wallet_funding_amount',
            ]);
        });
    }
};
