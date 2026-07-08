<?php

namespace Database\Seeders;

use App\Models\Discount;
use Illuminate\Database\Seeder;

class AirtimeToCashRatesSeeder extends Seeder
{
    /**
     * Seeds the conversion rate (as a Discount, service_type "airtimeToCash")
     * per network — the same percentage-cut model already used for
     * airtime/data/cable/electricity. AirtimeToCashRequest::submit() refuses
     * a network with no matching rate configured here.
     */
    public function run(): void
    {
        $networks = ['mtn', 'airtel', 'glo', '9mobile'];

        foreach ($networks as $network) {
            Discount::firstOrCreate(
                ['service_type' => 'airtimeToCash', 'network' => $network],
                ['name' => $network, 'discount_type' => 'percentage', 'value' => 5, 'active' => true],
            );
        }
    }
}
