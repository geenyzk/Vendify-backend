<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ServiceControlSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('service_controls')->insert([
            ['name' => 'glo', 'category' => 'airtime', 'sub_category' => 'vtu', 'isActive' => 1, 'isDevLock' => 0],
            ['name' => 'glo', 'category' => 'airtime', 'sub_category' => 'sns', 'isActive' => 0, 'isDevLock' => 0],
            ['name' => '9mobile', 'category' => 'recharge pin', 'sub_category' => 'recharge pin', 'isActive' => 0, 'isDevLock' => 0],
            ['name' => 'airtel', 'category' => 'data', 'sub_category' => 'gifting', 'isActive' => 0, 'isDevLock' => 0],
            ['name' => 'mtn', 'category' => 'airtime', 'sub_category' => 'vtu', 'isActive' => 0, 'isDevLock' => 0],
            ['name' => 'glo', 'category' => 'data', 'sub_category' => 'cooperate gifting', 'isActive' => 0, 'isDevLock' => 0],
            ['name' => 'glo', 'category' => 'data', 'sub_category' => 'gifting', 'isActive' => 0, 'isDevLock' => 0],
            ['name' => '9mobile', 'category' => 'airtime', 'sub_category' => 'vtu', 'isActive' => 1, 'isDevLock' => 0],
            ['name' => '9mobile', 'category' => 'data', 'sub_category' => 'gifting', 'isActive' => 0, 'isDevLock' => 0],
            [ 'name' => 'dstv', 'category' => 'cable', 'sub_category' => 'cable', 'isActive' => 0, 'isDevLock' => 0],
            [ 'name' => 'gotv', 'category' => 'cable', 'sub_category' => 'cable', 'isActive' => 0, 'isDevLock' => 0],
            [ 'name' => 'billstack', 'category' => 'transaction', 'sub_category' => 'payment gateway', 'isActive' => 0, 'isDevLock' => 1],
            [ 'name' => 'payment point', 'category' => 'transaction', 'sub_category' => 'payment gateway', 'isActive' => 0, 'isDevLock' => 1],
            [ 'name' => 'pin', 'category' => 'transaction', 'sub_category' => 'transaction', 'isActive' => 0, 'isDevLock' => 0],
            [ 'name' => 'moniepoint MFB', 'category' => 'transaction', 'sub_category' => 'bank', 'isActive' => 1, 'isDevLock' => 0],
            [ 'name' => 'paystack', 'category' => 'transaction', 'sub_category' => 'payment gateway', 'isActive' => 0, 'isDevLock' => 1],
            [ 'name' => 'wema bank', 'category' => 'transaction', 'sub_category' => 'bank', 'isActive' => 1, 'isDevLock' => 0],
            [ 'name' => 'glo', 'category' => 'airtime to cash', 'sub_category' => 'airtime to cash', 'isActive' => 1, 'isDevLock' => 0],
            [ 'name' => '9mobile', 'category' => 'airtime to cash', 'sub_category' => 'airtime to cash', 'isActive' => 1, 'isDevLock' => 0],
            [ 'name' => 'WAEC', 'category' => 'exam', 'sub_category' => 'exam', 'isActive' => 1, 'isDevLock' => 0],
            [ 'name' => 'NECO', 'category' => 'exam', 'sub_category' => 'exam', 'isActive' => 1, 'isDevLock' => 0],
            [ 'name' => 'mtn', 'category' => 'recharge pin', 'sub_category' => 'recharge pin', 'isActive' => 1, 'isDevLock' => 0],
            [ 'name' => 'NABTEB', 'category' => 'exam', 'sub_category' => 'exam', 'isActive' => 1, 'isDevLock' => 0],
            [ 'name' => 'mtn', 'category' => 'airtime', 'sub_category' => 'sns', 'isActive' => 1, 'isDevLock' => 0],
            [ 'name' => 'mtn', 'category' => 'data', 'sub_category' => 'sme', 'isActive' => 0, 'isDevLock' => 0],
            [ 'name' => 'mtn', 'category' => 'data', 'sub_category' => 'gifting', 'isActive' => 0, 'isDevLock' => 0],
            [ 'name' => 'mtn', 'category' => 'data', 'sub_category' => 'cooperate gifting', 'isActive' => 0, 'isDevLock' => 0],
            [ 'name' => 'airtel', 'category' => 'airtime', 'sub_category' => 'sns', 'isActive' => 0, 'isDevLock' => 0],
            [ 'name' => 'airtel', 'category' => 'airtime', 'sub_category' => 'vtu', 'isActive' => 1, 'isDevLock' => 0],
            [ 'name' => 'airtel', 'category' => 'data', 'sub_category' => 'sme', 'isActive' => 0, 'isDevLock' => 0],
            [ 'name' => 'airtel', 'category' => 'data', 'sub_category' => 'cooperate gifting', 'isActive' => 0, 'isDevLock' => 0],
            [ 'name' => 'glo', 'category' => 'data', 'sub_category' => 'sme', 'isActive' => 0, 'isDevLock' => 0],
            [ 'name' => '9mobile', 'category' => 'airtime', 'sub_category' => 'sns', 'isActive' => 0, 'isDevLock' => 0],
            [ 'name' => '9mobile', 'category' => 'data', 'sub_category' => 'cooperate gifting', 'isActive' => 0, 'isDevLock' => 0],
            [ 'name' => '9mobile', 'category' => 'data', 'sub_category' => 'sme', 'isActive' => 0, 'isDevLock' => 0],
            [ 'name' => 'startime', 'category' => 'cable', 'sub_category' => 'cable', 'isActive' => 0, 'isDevLock' => 0],
            [ 'name' => 'flutterwave', 'category' => 'transaction', 'sub_category' => 'payment gateway', 'isActive' => 1, 'isDevLock' => 0],
            [ 'name' => 'monnify', 'category' => 'transaction', 'sub_category' => 'payment gateway', 'isActive' => 0, 'isDevLock' => 0],
            [ 'name' => 'payment point', 'category' => 'transaction', 'sub_category' => 'payment gateway', 'isActive' => 0, 'isDevLock' => 0],
            [ 'name' => 'sterling bank', 'category' => 'transaction', 'sub_category' => 'bank', 'isActive' => 1, 'isDevLock' => 0],
            [ 'name' => 'palmpay', 'category' => 'transaction', 'sub_category' => 'bank', 'isActive' => 1, 'isDevLock' => 1],
            [ 'name' => 'airtel', 'category' => 'airtime to cash', 'sub_category' => 'airtime to cash', 'isActive' => 1, 'isDevLock' => 0],
            [ 'name' => 'mtn', 'category' => 'airtime to cash', 'sub_category' => 'airtime to cash', 'isActive' => 1, 'isDevLock' => 0],
            [ 'name' => 'airtel', 'category' => 'recharge pin', 'sub_category' => 'recharge pin', 'isActive' => 0, 'isDevLock' => 0],
            [ 'name' => 'glo', 'category' => 'recharge pin', 'sub_category' => 'recharge pin', 'isActive' => 1, 'isDevLock' => 0],
            // Add after the last entry in your existing insert array:
            [ 'name' => 'Ibadan Electricity', 'category' => 'electricity', 'sub_category' => 'electricity', 'isActive' => 1, 'isDevLock' => 0],
            [ 'name' => 'Jos Electricity', 'category' => 'electricity', 'sub_category' => 'electricity', 'isActive' => 1, 'isDevLock' => 0],
            [ 'name' => 'Ikeja Electricity', 'category' => 'electricity', 'sub_category' => 'electricity', 'isActive' => 1, 'isDevLock' => 0],
            [ 'name' => 'Port Harcourt Electricity', 'category' => 'electricity', 'sub_category' => 'electricity', 'isActive' => 1, 'isDevLock' => 0],
            [ 'name' => 'Kaduna Electricity', 'category' => 'electricity', 'sub_category' => 'electricity', 'isActive' => 1, 'isDevLock' => 0],
            [ 'name' => 'Abuja Electricity', 'category' => 'electricity', 'sub_category' => 'electricity', 'isActive' => 1, 'isDevLock' => 0],
            [ 'name' => 'Eko Electricity', 'category' => 'electricity', 'sub_category' => 'electricity', 'isActive' => 1, 'isDevLock' => 0],
            [ 'name' => 'Enugu Electricity', 'category' => 'electricity', 'sub_category' => 'electricity', 'isActive' => 1, 'isDevLock' => 0],
            [ 'name' => 'Kano Electricity', 'category' => 'electricity', 'sub_category' => 'electricity', 'isActive' => 1, 'isDevLock' => 0],
            [ 'name' => 'Benin Electricity', 'category' => 'electricity', 'sub_category' => 'electricity', 'isActive' => 1, 'isDevLock' => 0],
            [ 'name' => 'Yola Electricity', 'category' => 'electricity', 'sub_category' => 'electricity', 'isActive' => 1, 'isDevLock' => 0],
            [ 'name' => 'Aba Electricity', 'category' => 'electricity', 'sub_category' => 'electricity', 'isActive' => 1, 'isDevLock' => 0],

        ]);
    }
}
