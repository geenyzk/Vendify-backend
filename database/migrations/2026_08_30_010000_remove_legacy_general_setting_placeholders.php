<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OPTIONAL_COLUMNS = [
        'app_phone',
        'app_address',
        'bvn',
        'bankName',
        'accountName',
        'accountNumber',
        'logo',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('generals')) {
            return;
        }

        $columns = array_values(array_filter(
            self::OPTIONAL_COLUMNS,
            fn (string $column) => Schema::hasColumn('generals', $column),
        ));

        Schema::table('generals', function (Blueprint $table) use ($columns) {
            foreach ($columns as $column) {
                $table->string($column)->nullable()->default(null)->change();
            }
        });

        foreach ($columns as $column) {
            DB::table('generals')->where($column, '#')->update([$column => null]);
        }
    }

    public function down(): void
    {
        // Do not restore meaningless "#" placeholder values.
    }
};
