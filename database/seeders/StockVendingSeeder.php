<?php

namespace Database\Seeders;

use App\Models\StockVending;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class StockVendingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {

         StockVending::create([]);
    }
}
