<?php

namespace Database\Seeders;

use App\Models\ExamPlan;
use App\Models\General;
use App\Models\StockVending;
use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();


        General::create([
            "app_name" => "Vendify"
        ]);

        $this->call([
            PermissionSeeder::class,
            ServiceControlSeeder::class,
            DiscountsSeeder::class,
            DataPlanSeeder::class,
            BillPlanSeeder::class,
            StockVendingSeeder::class,
            ProviderSeeder::class,
            MessageSeeder::class,
            TemplateSeeder::class,
            ExamPlan::class,
            AirtimeToCashRatesSeeder::class,
            // Promotions used by frontend promotions UI
            PromotionSeeder::class,
            CashbackRateSeeder::class,
        ]);
    }
}
