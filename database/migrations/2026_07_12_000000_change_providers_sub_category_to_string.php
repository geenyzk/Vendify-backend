<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * sub_category was a MySQL enum, so every new provider integration
     * (here: 'simvend' for SIM vending) required an ALTER. A plain string
     * keeps the same data and frees VendorFactory::REGISTRY to be the only
     * place a new type has to be declared.
     */
    public function up(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            $table->string('sub_category')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            $table->enum('sub_category', ['adex', 'spurs', 'msorg', 'simhost', 'misc', 'payment', 'simvend'])
                ->nullable()
                ->change();
        });
    }
};
