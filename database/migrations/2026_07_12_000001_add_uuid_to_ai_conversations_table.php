<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->unique()->after('title');
        });

        // Backfill existing rows with generated uuids so old conversations are addressable.
        $rows = DB::table('ai_conversations')->whereNull('uuid')->get(['id']);
        foreach ($rows as $row) {
            DB::table('ai_conversations')->where('id', $row->id)->update(['uuid' => (string) Str::uuid()]);
        }

        // Make column non-nullable now that existing rows have values.
        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->uuid('uuid')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->dropUnique(['uuid']);
            $table->dropColumn('uuid');
        });
    }
};
