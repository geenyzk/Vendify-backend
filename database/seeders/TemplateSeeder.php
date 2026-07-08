<?php

namespace Database\Seeders;

use App\Models\Template;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class TemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
     {
        $templates = [
            [
                'name' => 'Welcome message',
                'type' => 'event',
                'event' => 'register',
                'subject' => 'Welcome to {{app_name}}, {{name}}!',
                'content' => "Hi {{name}}, welcome to {{app_name}}! Your account is ready — top up your wallet to get started with airtime, data, cable, and bill payments.",
                'channels' => ['email', 'in_app'],
                'enabled' => true,
            ],
            [
                'name' => 'Login alert (email)',
                'type' => 'event',
                'event' => 'login',
                'subject' => 'New login detected on your account',
                'content' => "Hi {{name}}, a new login was detected at {{time}} from IP {{ip}}. If this wasn't you, please reset your password immediately.",
                'channels' => ['email', 'in_app'],
                'enabled' => true,
            ],
            [
                'name' => 'Purchase successful (receipt)',
                'type' => 'event',
                'event' => 'purchase',
                'subject' => 'Purchase successful — {{service}}',
                'content' => "Hello {{name}}, your purchase of {{service}} for ₦{{amount}} succeeded. Reference: {{reference}}.",
                'channels' => ['sms', 'email', 'in_app'],
                'enabled' => true,
            ],
            [
                'name' => 'Weekly promo (broadcast)',
                'type' => 'broadcast',
                'content' => "Hey {{name}}, this weekend only: 15% off selected data plans. Use code WEEKEND15. Offer expires {{expiry}}.",
                'channels' => ['email', 'in_app'],
                'enabled' => false,
            ],
        ];

        foreach ($templates as $tpl) {
            $slug = \Illuminate\Support\Str::slug($tpl['name']);
            Template::firstOrCreate(['slug' => $slug], array_merge($tpl, ['slug' => $slug]));
        }
    }
}

