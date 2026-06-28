<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AirtimeToCashRatesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        //
         DB::table('discounts')->insert([
            [
                'name' => 'mtn',
                'type' => 'airtimeToCash',
                'user_discount' => 3.00,
                'bonanza_discount' => 5.00,
                'agent_discount' => 4.00,
                'api_discount' => 2.50,
                'min' => 50.00,
                'max' => 5000.00,
                "category" => null
            ],
            [
                'name' => 'airtel',
                'type' => 'airtimeToCash',
                'user_discount' => 3.00,
                'bonanza_discount' => 5.00,
                'agent_discount' => 4.00,
                'api_discount' => 2.50,
                'min' => 50.00,
                'max' => 5000.00,
                "category" => null
            ],
            [
                'name' => 'glo',
                'type' => 'airtimeToCash',
                'user_discount' => 3.00,
                'bonanza_discount' => 5.00,
                'agent_discount' => 4.00,
                'api_discount' => 2.50,
                'min' => 50.00,
                'max' => 5000.00,
                "category" => null
            ],
            [
                'name' => '9mobile',
                'type' => 'airtimeToCash',
                'user_discount' => 3.00,
                'bonanza_discount' => 5.00,
                'agent_discount' => 4.00,
                'api_discount' => 2.50,
                'min' => 50.00,
                'max' => 5000.00,
                "category" => null
            ],
        ]);
    }
}
