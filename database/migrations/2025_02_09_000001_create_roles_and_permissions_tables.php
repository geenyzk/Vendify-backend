<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Reconciling migration: existing databases already carry a February-era
// version of these tables (roles with slug/description/is_active/upgradable/
// upgrade_cost, permissions with slug/description, a permission_role pivot,
// and users.role_id) whose create-migration is no longer in the repo. A plain
// Schema::create would collide with them, so every step here is guarded —
// fresh installs get the full table, legacy databases only gain the new
// is_staff column. The legacy columns are kept on fresh installs too because
// they are still read in production code (CustomerController::upgrade reads
// slug/upgradable/upgrade_cost; the SPA matches permissions by slug).
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();
                $table->string('slug')->nullable()->unique();
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true);
                // Whether a customer can self-upgrade into this role from
                // /upgrade-account, and what it costs (CustomerController).
                $table->boolean('upgradable')->default(false);
                $table->decimal('upgrade_cost', 12, 2)->nullable();
                // Whether this role may be granted access to the /admin panel
                // at all — customer-facing roles (api, agent, bonanza, basic)
                // stay false and are never checked against permissions.
                $table->boolean('is_staff')->default(false);
                $table->timestamps();
            });
        } elseif (!Schema::hasColumn('roles', 'is_staff')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->boolean('is_staff')->default(false)->after('name');
            });
        }

        if (!Schema::hasTable('permissions')) {
            Schema::create('permissions', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();
                $table->string('slug')->nullable()->unique();
                $table->text('description')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('permission_role')) {
            Schema::create('permission_role', function (Blueprint $table) {
                $table->foreignId('role_id')->constrained()->cascadeOnDelete();
                $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
                $table->primary(['role_id', 'permission_id']);
            });
        }

        if (!Schema::hasColumn('users', 'role_id')) {
            Schema::table('users', function (Blueprint $table) {
                // Separate from user_type (which drives pricing-tier lookups
                // like `{$user_type}_price` on DataPlan/CablePlan/etc). role_id
                // is purely for /admin panel access + granular permissions.
                $table->foreignId('role_id')->nullable()->after('user_type')
                    ->constrained()->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('users', 'role_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropConstrainedForeignId('role_id');
            });
        }

        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
