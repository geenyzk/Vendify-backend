<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only trail of who changed what on the platform. Written automatically
 * for create/update/delete on audited models (see the Auditable trait) and
 * explicitly for sensitive non-model events (logins, refunds, wallet funding,
 * AI-action approvals, website reset).
 *
 * Actor identity is snapshotted (actor_name/email) alongside the FK so the
 * record still reads correctly if the user is later renamed or deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('audit_logs')) {
            return;
        }

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name')->nullable();
            $table->string('actor_email')->nullable();

            // e.g. created, updated, deleted, login, refund, wallet_fund.
            $table->string('action', 60);

            // Polymorphic subject of the action (null for non-model events).
            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->string('subject_label')->nullable();

            $table->text('description')->nullable();
            // Field-level diff for updates ({field:{old,new}}) or the created/
            // deleted attributes; secrets are redacted before they get here.
            $table->json('changes')->nullable();
            // Extra machine context (route, category, ids) — never shown as the
            // primary description but available on the expanded row.
            $table->json('context')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();

            $table->timestamps();

            $table->index('action');
            $table->index('user_id');
            $table->index('created_at');
            $table->index(['auditable_type', 'auditable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
