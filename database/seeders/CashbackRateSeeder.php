<?php

namespace Database\Seeders;

use App\Models\CashbackRate;
use Illuminate\Database\Seeder;

class CashbackRateSeeder extends Seeder
{
    /**
     * Run the database seeds. Rates start at 0% (active but inert) — an
     * admin opts in per service by raising the percentage above zero.
     */
    public function run(): void
    {
        foreach (['airtime_recharge', 'data_subscription', 'cable_subscription', 'electricity_bill'] as $type) {
            CashbackRate::firstOrCreate(['service_type' => $type], ['percentage' => 0, 'active' => true]);
        }
    }
}
