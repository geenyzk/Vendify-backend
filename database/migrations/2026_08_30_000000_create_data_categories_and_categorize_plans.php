<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $now = now();
        $categories = [
            ['Daily', 'daily'], ['Weekly', 'weekly'], ['Monthly', 'monthly'],
            ['2 Months', 'two-months'], ['3 Months', 'three-months'],
            ['3+ Months', 'long-term'], ['Yearly', 'yearly'], ['Social', 'social'],
            ['Night', 'night'], ['Weekend', 'weekend'], ['Unlimited', 'unlimited'],
            ['Special / Promo', 'special'],
        ];
        DB::table('data_categories')->insert(array_map(
            fn (array $category, int $index) => [
                'name' => $category[0], 'slug' => $category[1],
                'sort_order' => ($index + 1) * 10, 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ],
            $categories,
            array_keys($categories),
        ));

        Schema::table('data_plans', function (Blueprint $table) {
            $table->foreignId('auto_category_id')->nullable()->after('network_type_id')
                ->constrained('data_categories')->nullOnDelete();
            $table->foreignId('manual_category_id')->nullable()->after('auto_category_id')
                ->constrained('data_categories')->nullOnDelete();
            $table->boolean('is_featured')->default(false)->after('is_draft');
        });

        $classifier = app(\App\Services\DataPlanCategoryClassifier::class);
        $categoryIds = DB::table('data_categories')->pluck('id', 'slug');
        DB::table('data_plans')->orderBy('id')->chunkById(200, function ($plans) use ($classifier, $categoryIds) {
            foreach ($plans as $plan) {
                $result = $classifier->classify(
                    trim((string) $plan->plan_name.' '.(string) $plan->plan_size),
                    (string) $plan->validity,
                    (string) $plan->plan_type,
                );
                DB::table('data_plans')->where('id', $plan->id)->update([
                    'auto_category_id' => $categoryIds[$result['slug']] ?? null,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('data_plans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('manual_category_id');
            $table->dropConstrainedForeignId('auto_category_id');
            $table->dropColumn('is_featured');
        });
        Schema::dropIfExists('data_categories');
    }
};
