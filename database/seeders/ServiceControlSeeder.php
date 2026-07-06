<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ServiceControlSeeder extends Seeder
{
    /**
     * Only seeds the "transaction" category (payment gateway/bank/pin
     * toggles) — the sole surviving use of Service Control. Availability for
     * every other category (airtime/data/cable/exam/electricity/recharge pin/
     * airtime to cash) is superseded by each service's own admin-configured
     * plan `active` flag (Airtime Plan, Data Plan, Cable Plan, Bill Plan) and
     * is no longer read from service_controls.
     */
    public function run(): void
    {
        DB::table('service_controls')->insert([
            ['name' => 'billstack', 'category' => 'transaction', 'sub_category' => 'payment gateway', 'isActive' => 0, 'isDevLock' => 1],
            ['name' => 'payment point', 'category' => 'transaction', 'sub_category' => 'payment gateway', 'isActive' => 0, 'isDevLock' => 1],
            ['name' => 'pin', 'category' => 'transaction', 'sub_category' => 'transaction', 'isActive' => 0, 'isDevLock' => 0],
            ['name' => 'moniepoint MFB', 'category' => 'transaction', 'sub_category' => 'bank', 'isActive' => 1, 'isDevLock' => 0],
            ['name' => 'paystack', 'category' => 'transaction', 'sub_category' => 'payment gateway', 'isActive' => 0, 'isDevLock' => 1],
            ['name' => 'wema bank', 'category' => 'transaction', 'sub_category' => 'bank', 'isActive' => 1, 'isDevLock' => 0],
            ['name' => 'flutterwave', 'category' => 'transaction', 'sub_category' => 'payment gateway', 'isActive' => 1, 'isDevLock' => 0],
            ['name' => 'monnify', 'category' => 'transaction', 'sub_category' => 'payment gateway', 'isActive' => 0, 'isDevLock' => 0],
            ['name' => 'sterling bank', 'category' => 'transaction', 'sub_category' => 'bank', 'isActive' => 1, 'isDevLock' => 0],
            ['name' => 'palmpay', 'category' => 'transaction', 'sub_category' => 'bank', 'isActive' => 1, 'isDevLock' => 1],
        ]);
    }
}
