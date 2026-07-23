<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, array<string, array<int, string>>> */
    private array $indexes = [
        'transactions' => [
            'transactions_created_index' => ['created_at'],
            'transactions_user_status_created_index' => ['user_id', 'status', 'created_at'],
        ],
        'users' => [
            'users_created_index' => ['created_at'],
        ],
        'airtime_to_cash_requests' => [
            'atc_user_created_index' => ['user_id', 'created_at'],
            'atc_status_created_index' => ['status', 'created_at'],
        ],
        'wallet_withdrawals' => [
            'withdrawals_user_created_index' => ['user_id', 'created_at'],
            'withdrawals_status_created_index' => ['status', 'created_at'],
        ],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $tableName => $indexes) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            foreach ($indexes as $indexName => $columns) {
                if (! Schema::hasIndex($tableName, $indexName)) {
                    Schema::table(
                        $tableName,
                        fn (Blueprint $table) => $table->index($columns, $indexName)
                    );
                }
            }
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->indexes, true) as $tableName => $indexes) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            foreach (array_reverse($indexes, true) as $indexName => $_columns) {
                if (Schema::hasIndex($tableName, $indexName)) {
                    Schema::table(
                        $tableName,
                        fn (Blueprint $table) => $table->dropIndex($indexName)
                    );
                }
            }
        }
    }
};
