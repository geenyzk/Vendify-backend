<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Schema drift (dump imports): the table may already exist without
        // this migration being recorded — skip instead of aborting the run.
        if (Schema::hasTable('broadcasts')) {
            return;
        }

        Schema::create('broadcasts', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->text('message')->nullable();
            $table->json('channels')->nullable();
            $table->string('audience_label')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->boolean('sent')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('broadcasts');
    }
};
