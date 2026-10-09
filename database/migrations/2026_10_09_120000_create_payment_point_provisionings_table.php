<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('payment_point_provisionings')) {
            return;
        }

        Schema::create('payment_point_provisionings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->string('status', 32);
            $table->string('last_error_category', 64)->nullable();
            $table->boolean('customer_created')->default(false);
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('kyc_consent_at')->nullable();
            $table->timestamp('kyc_attempted_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'provider_id'], 'payment_point_provisioning_user_provider_unique');
            $table->index(['status', 'updated_at'], 'payment_point_provisioning_status_index');
        });

        // These are the three production users whose failed PaymentPoint
        // allocation attempts are evidenced in the incident logs. Mark only
        // those unresolved rows so opening Wallet cannot submit another create
        // request before the duplicate-safe KYC recovery path is enabled.
        $providerId = DB::table('providers')
            ->whereRaw("LOWER(REPLACE(TRIM(name), ' ', '')) = ?", ['paymentpoint'])
            ->value('id');

        if ($providerId) {
            $now = now();
            $unresolvedUserIds = DB::table('users')
                ->whereIn('id', [435, 436, 437])
                ->whereNotExists(function ($query) {
                    $query->selectRaw('1')
                        ->from('banks')
                        ->whereColumn('banks.user_id', 'users.id')
                        ->where('banks.status', 'active')
                        ->whereRaw("LOWER(REPLACE(TRIM(banks.provider), ' ', '')) = ?", ['paymentpoint'])
                        ->whereNotNull('banks.bank_account')
                        ->whereRaw('LENGTH(TRIM(banks.bank_account)) > 0')
                        ->whereNotNull('banks.bank_name')
                        ->whereRaw('LENGTH(TRIM(banks.bank_name)) > 0');
                })
                ->pluck('id');

            DB::table('payment_point_provisionings')->insertOrIgnore(
                $unresolvedUserIds->map(fn ($userId) => [
                    'user_id' => $userId,
                    'provider_id' => $providerId,
                    'status' => 'kyc_required',
                    'last_error_category' => 'confirmed_allocation_failure',
                    // The historic logs prove an allocation failure but did
                    // not expose whether customer.customer_id was populated.
                    'customer_created' => false,
                    'attempt_count' => 1,
                    'last_attempt_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all(),
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_point_provisionings');
    }
};
