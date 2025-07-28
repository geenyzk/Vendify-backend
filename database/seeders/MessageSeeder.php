<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class MessageSeeder extends Seeder
{
    public function run(): void
    {
        $loginHtml = File::get(resource_path('views/emails/login.blade.php'));
        $registrationHtml = File::get(resource_path('views/emails/registration.blade.php'));
        $passwordResetHtml = File::get(resource_path('views/emails/reset-password.blade.php'));

        DB::table('messages')->insert([
            [
                'purpose' => 'login',
                'body' => $loginHtml,
            ],
            [
                'purpose' => 'registration',
                'body' => $registrationHtml,
            ],
            [
                'purpose' => 'password_reset',
                'body' => $passwordResetHtml,
            ],
        ]);
    }
}
