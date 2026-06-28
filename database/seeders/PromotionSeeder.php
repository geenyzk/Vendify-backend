<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PromotionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('promotions')->insert([
            [
                'name' => 'MTN Data Bonus 20%',
                'code' => 'MTN20',
                'apply' => 'code',
                'target' => 'customer',
                'product' => 'data',
                'provider' => 'MTN',
                'type' => 'percentage',
                'value' => 20,
                'active' => true,
                'starts_at' => '2026-01-01',
                'ends_at' => '2026-02-28',
                'usage_limit_total' => 5000,
                'usage_limit_per_customer' => 2,
                'used' => 134,
                'conditions' => json_encode([
                    ['kind' => 'min_amount', 'amount' => 500],
                    ['kind' => 'provider', 'provider' => 'MTN'],
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Reseller Cashback Tier A',
                'code' => null,
                'apply' => 'auto',
                'target' => 'reseller',
                'product' => 'bundle',
                'provider' => 'All',
                'type' => 'cashback',
                'value' => 50,
                'active' => false,
                'starts_at' => '2026-03-01',
                'ends_at' => '2026-03-31',
                'usage_limit_total' => 10000,
                'usage_limit_per_customer' => null,
                'used' => 10,
                'conditions' => json_encode([
                    ['kind' => 'reseller_tier', 'tier' => 'A'],
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
                [
                    'name' => 'Airtel Airtime 15% Discount',
                    'code' => 'AIR15',
                    'apply' => 'code',
                    'target' => 'customer',
                    'product' => 'airtime',
                    'provider' => 'AIRTEL',
                    'type' => 'percentage',
                    'value' => 15,
                    'active' => true,
                    'starts_at' => '2026-01-15',
                    'ends_at' => '2026-12-31',
                    'usage_limit_total' => 10000,
                    'usage_limit_per_customer' => 5,
                    'used' => 234,
                    'conditions' => json_encode([
                        ['kind' => 'min_amount', 'amount' => 100],
                    ]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'name' => 'GLO Data Bonus 1GB',
                    'code' => 'GLO1GB',
                    'apply' => 'code',
                    'target' => 'customer',
                    'product' => 'data',
                    'provider' => 'GLO',
                    'type' => 'bonus_data',
                    'value' => 1024,
                    'active' => true,
                    'starts_at' => '2026-02-01',
                    'ends_at' => '2026-02-28',
                    'usage_limit_total' => 1000,
                    'usage_limit_per_customer' => 1,
                    'used' => 45,
                    'conditions' => json_encode([
                        ['kind' => 'min_amount', 'amount' => 1000],
                        ['kind' => 'provider', 'provider' => 'GLO'],
                    ]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'name' => '9MOBILE Fixed Amount ₦50',
                    'code' => 'NINE50',
                    'apply' => 'code',
                    'target' => 'both',
                    'product' => 'airtime',
                    'provider' => '9MOBILE',
                    'type' => 'fixed',
                    'value' => 50,
                    'active' => true,
                    'starts_at' => '2026-01-01',
                    'ends_at' => '2026-12-31',
                    'usage_limit_total' => 20000,
                    'usage_limit_per_customer' => 10,
                    'used' => 567,
                    'conditions' => json_encode([
                        ['kind' => 'min_amount', 'amount' => 500],
                    ]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'name' => 'New Year Special - All Networks 25%',
                    'code' => 'NEWYEAR25',
                    'apply' => 'code',
                    'target' => 'customer',
                    'product' => 'airtime',
                    'provider' => 'All',
                    'type' => 'percentage',
                    'value' => 25,
                    'active' => false,
                    'starts_at' => '2025-12-25',
                    'ends_at' => '2026-01-05',
                    'usage_limit_total' => 50000,
                    'usage_limit_per_customer' => 3,
                    'used' => 48956,
                    'conditions' => json_encode([
                        ['kind' => 'min_amount', 'amount' => 200],
                    ]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'name' => 'Referral Bonus - ₦100 Cashback',
                    'code' => 'REF100',
                    'apply' => 'auto',
                    'target' => 'customer',
                    'product' => 'airtime',
                    'provider' => 'All',
                    'type' => 'cashback',
                    'value' => 100,
                    'active' => true,
                    'starts_at' => '2026-01-01',
                    'ends_at' => null,
                    'usage_limit_total' => null,
                    'usage_limit_per_customer' => 1,
                    'used' => 345,
                    'conditions' => json_encode([]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);
        }@@
