<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ExamPlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        //
        DB::table("exam_plans")->insert([
            [
                "name" => "NECO",
                "active" => true,
                "user_discount" => 0,
                "bonanza_discount" => 0,
                "agent_discount" => 0,
                "api_discount" => 0,
            ],

            [
                "name" => "WAEC",
                "active" => true,
                "user_discount" => 0,
                "bonanza_discount" => 0,
                "agent_discount" => 0,
                "api_discount" => 0,
            ],

            [
                "name" => "NABTEB",
                "active" => true,
                "user_discount" => 0,
                "bonanza_discount" => 0,
                "agent_discount" => 0,
                "api_discount" => 0,
            ],
        ]);
    }
}
