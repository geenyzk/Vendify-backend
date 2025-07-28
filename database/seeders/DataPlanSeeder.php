<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DataPlanSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();

        $networks = ['MTN', 'Airtel', 'Glo', '9mobile'];

        $plans = [
            '100MB' => 100,
            '200MB' => 200,
            '500MB' => 500,
            '1GB'   => 1024,
            '2GB'   => 2048,
            '5GB'   => 5120,
            '10GB'  => 10240,
            '20GB'  => 20480,
        ];

        $planTypes = ['sme', 'gifting', 'cooperate_gifting'];

        foreach ($networks as $network) {
            foreach ($planTypes as $type) {
                foreach ($plans as $name => $mb) {
                    preg_match('/(\d+)([a-zA-Z]+)/', $name, $matches);
                    DB::table('data_plans')->insert([
                        'network'    => $network,
                        'plan_name'  => $matches[1],
                        'plan_type'  => $type,
                        'plan_size'  => $matches[2],
                        'validity'   => '30 days', // default validity
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }
}
