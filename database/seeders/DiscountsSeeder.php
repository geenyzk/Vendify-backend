<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DiscountsSeeder extends Seeder
{
    public function run()
    {
        DB::table('discounts')->insert([
            [
                'name' => 'mtn',
                'type' => 'airtime',
                'user_discount' => 3.00,
                'bonanza_discount' => 5.00,
                'agent_discount' => 4.00,
                'api_discount' => 2.50,
                'min' => 50.00,
                'max' => 5000.00,
                "category" => "sns"
            ],
            [
                'name' => 'mtn',
                'type' => 'airtime',
                'user_discount' => 3.00,
                'bonanza_discount' => 5.00,
                'agent_discount' => 4.00,
                'api_discount' => 2.50,
                'min' => 50.00,
                'max' => 5000.00,
                "category" => "vtu"
            ],
            [
                'name' => 'airtel',
                'type' => 'airtime',
                'user_discount' => 3.00,
                'bonanza_discount' => 5.00,
                'agent_discount' => 4.00,
                'api_discount' => 2.50,
                'min' => 50.00,
                'max' => 5000.00,
                "category" => "sns"
            ],


             [
                'name' => 'airtel',
                'type' => 'airtime',
                'user_discount' => 3.00,
                'bonanza_discount' => 5.00,
                'agent_discount' => 4.00,
                'api_discount' => 2.50,
                'min' => 50.00,
                'max' => 5000.00,
                "category" => "vtu"
            ],
            [
                'name' => 'glo',
                'type' => 'airtime',
                'user_discount' => 3.00,
                'bonanza_discount' => 5.00,
                'agent_discount' => 4.00,
                'api_discount' => 2.50,
                'min' => 50.00,
                'max' => 5000.00,
                "category" => "vtu"
            ],
            [
                'name' => 'glo',
                'type' => 'airtime',
                'user_discount' => 3.00,
                'bonanza_discount' => 5.00,
                'agent_discount' => 4.00,
                'api_discount' => 2.50,
                'min' => 50.00,
                'max' => 5000.00,
                "category" => "sns"
            ],
            [
                'name' => '9 mobile',
                'type' => 'airtime',
                'user_discount' => 3.00,
                'bonanza_discount' => 5.00,
                'agent_discount' => 4.00,
                'api_discount' => 2.50,
                'min' => 50.00,
                'max' => 5000.00,
                "category" => "vtu"

            ],

            [
                'name' => '9 mobile',
                'type' => 'airtime',
                'user_discount' => 3.00,
                'bonanza_discount' => 5.00,
                'agent_discount' => 4.00,
                'api_discount' => 2.50,
                'min' => 50.00,
                'max' => 5000.00,
                "category" => "sns"

            ],


            [
                'name' => 'Ikeja Electricity',
                'type' => 'electricity',
                'user_discount' => 3.00,
                'bonanza_discount' => 5.00,
                'agent_discount' => 4.00,
                'api_discount' => 2.50,
                'min' => 50.00,
                'max' => 5000.00,
                "category" => null

            ],
            [
                'name' => 'Eko Electricity',
                'type' => 'electricity',
                'user_discount' => 3.00,
                'bonanza_discount' => 5.00,
                'agent_discount' => 4.00,
                'api_discount' => 2.50,
                'min' => 50.00,
                'max' => 5000.00,
                "category" => null
            ],
            [
                'name' => 'Ibadan Electricity',
                'type' => 'electricity',
                'user_discount' => 3.00,
                'bonanza_discount' => 5.00,
                'agent_discount' => 4.00,
                'api_discount' => 2.50,
                'min' => 50.00,
                'max' => 5000.00,
                "category" => null
            ],
            [
                'name' => 'Kaduna Electricity',
                'type' => 'electricity',
                'user_discount' => 3.00,
                'bonanza_discount' => 5.00,
                'agent_discount' => 4.00,
                'api_discount' => 2.50,
                'min' => 50.00,
                'max' => 5000.00,
                "category" => null
            ],
            [
                'name' => 'Kano Electricity',
                'type' => 'electricity',
                'user_discount' => 3.00,
                'bonanza_discount' => 5.00,
                'agent_discount' => 4.00,
                'api_discount' => 2.50,
                'min' => 50.00,
                'max' => 5000.00,
                "category" => null
            ],
            [
                'name' => 'Jos Electricity',
                'type' => 'electricity',
                'user_discount' => 3.00,
                'bonanza_discount' => 5.00,
                'agent_discount' => 4.00,
                'api_discount' => 2.50,
                'min' => 50.00,
                'max' => 5000.00,
                "category" => null
            ],
            [
                'name' => 'Port Harcourt Electricity',
                'type' => 'electricity',
                'user_discount' => 3.00,
                'bonanza_discount' => 5.00,
                'agent_discount' => 4.00,
                'api_discount' => 2.50,
                'min' => 50.00,
                'max' => 5000.00,
                "category" => null
            ],
            [
                'name' => 'Yola Electricity',
                'type' => 'electricity',
                'user_discount' => 3.00,
                'bonanza_discount' => 5.00,
                'agent_discount' => 4.00,
                'api_discount' => 2.50,
                'min' => 50.00,
                'max' => 5000.00,
                "category" => null
            ],
            [
                'name' => 'Benin Electricity',
                'type' => 'electricity',
                'user_discount' => 3.00,
                'bonanza_discount' => 5.00,
                'agent_discount' => 4.00,
                'api_discount' => 2.50,
                'min' => 50.00,
                'max' => 5000.00,
                "category" => null
            ],
            [
                'name' => 'Enugu Electricity',
                'type' => 'electricity',
                'user_discount' => 3.00,
                'bonanza_discount' => 5.00,
                'agent_discount' => 4.00,
                'api_discount' => 2.50,
                'min' => 50.00,
                'max' => 5000.00,
                "category" => null
            ],
            [
                'name' => 'Abuja Electricity',
                'type' => 'electricity',
                'user_discount' => 3.00,
                'bonanza_discount' => 5.00,
                'agent_discount' => 4.00,
                'api_discount' => 2.50,
                'min' => 50.00,
                'max' => 5000.00,
                "category" => null
            ],
            [
                'name' => 'waec',
                'type' => 'exam',
                'user_discount' => 3.00,
                'bonanza_discount' => 5.00,
                'agent_discount' => 4.00,
                'api_discount' => 2.50,
                'min' => 50.00,
                'max' => 5000.00,
                "category" => null
            ],
            [
                'name' => 'neco',
                'type' => 'exam',
                'user_discount' => 3.00,
                'bonanza_discount' => 5.00,
                'agent_discount' => 4.00,
                'api_discount' => 2.50,
                'min' => 50.00,
                'max' => 5000.00,
                "category" => null
            ],
            [
                'name' => 'nabteb',
                'type' => 'exam',
                'user_discount' => 3.00,
                'bonanza_discount' => 5.00,
                'agent_discount' => 4.00,
                'api_discount' => 2.50,
                'min' => 50.00,
                'max' => 5000.00,
                "category" => null
            ],
            [
                'name' => 'user',
                'type' => 'user_upgrade',
                'user_discount' => 3.00,
                'bonanza_discount' => 5.00,
                'agent_discount' => 4.00,
                'api_discount' => 2.50,
                'min' => 50.00,
                'max' => 5000.00,
                "category" => null
            ],

            [
                'name' => 'bonanza',
                'type' => 'user_upgrade',
                'user_discount' => 3.00,
                'bonanza_discount' => 5.00,
                'agent_discount' => 4.00,
                'api_discount' => 2.50,
                'min' => 50.00,
                'max' => 5000.00,
                "category" => null
            ],

            [
                'name' => 'agent',
                'type' => 'user_upgrade',
                'user_discount' => 3.00,
                'bonanza_discount' => 5.00,
                'agent_discount' => 4.00,
                'api_discount' => 2.50,
                'min' => 50.00,
                'max' => 5000.00,
                "category" => null
            ],

            [
                'name' => 'api',
                'type' => 'user_upgrade',
                'user_discount' => 3.00,
                'bonanza_discount' => 5.00,
                'agent_discount' => 4.00,
                'api_discount' => 2.50,
                'min' => 50.00,
                'max' => 5000.00,
                "category" => null
            ],

            [
                'name' => 'bulksms',
                'type' => 'bulksms',
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
