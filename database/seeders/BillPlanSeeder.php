<?php

namespace Database\Seeders;

use App\Models\BillPlan;
use Illuminate\Database\Seeder;

class BillPlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Same disco list already shown (as static mock data) on the customer
     * Pricing page — gives every disco a sensible default range so Bill
     * plans aren't empty on a fresh install. No provider/service_fee is
     * attached here; that's an admin follow-up once a real vendor mapping
     * (disco ID) is known for each one.
     */
    public function run(): void
    {
        $discos = [
            'EKEDC (Eko Electric)',
            'IKEDC (Ikeja Electric)',
            'AEDC (Abuja Electric)',
            'PHEDC (Port Harcourt Electric)',
            'EEDC (Enugu Electric)',
            'IBEDC (Ibadan Electric)',
        ];

        foreach ($discos as $disco) {
            BillPlan::firstOrCreate(
                ['disco' => $disco],
                ['min' => 500, 'max' => 100000, 'active' => true],
            );
        }
    }
}
