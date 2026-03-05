<?php

namespace Database\Seeders;

use App\Models\Service;
use Illuminate\Database\Seeder;

class ServiceSeeder extends Seeder
{
    public function run(): void
    {
        $services = [
            ['name' => 'WhatsApp',   'color' => '#25D366', 'category' => 'Messaging',     'cost' => 120, 'icon' => 'whatsapp'],
            ['name' => 'Telegram',   'color' => '#26A5E4', 'category' => 'Messaging',     'cost' => 80,  'icon' => 'telegram'],
            ['name' => 'Gmail',      'color' => '#EA4335', 'category' => 'Email',          'cost' => 150, 'icon' => 'gmail'],
            ['name' => 'Facebook',   'color' => '#1877F2', 'category' => 'Social',         'cost' => 100, 'icon' => 'facebook'],
            ['name' => 'Instagram',  'color' => '#E1306C', 'category' => 'Social',         'cost' => 100, 'icon' => 'instagram'],
            ['name' => 'Twitter',    'color' => '#1DA1F2', 'category' => 'Social',         'cost' => 90,  'icon' => 'twitter'],
            ['name' => 'TikTok',     'color' => '#FF0050', 'category' => 'Social',         'cost' => 110, 'icon' => 'tiktok'],
            ['name' => 'Snapchat',   'color' => '#FFFC00', 'category' => 'Social',         'cost' => 95,  'icon' => 'snapchat'],
            ['name' => 'Amazon',     'color' => '#FF9900', 'category' => 'Shopping',       'cost' => 130, 'icon' => 'amazon'],
            ['name' => 'Uber',       'color' => '#090909', 'category' => 'Transport',      'cost' => 140, 'icon' => 'uber'],
            ['name' => 'Discord',    'color' => '#5865F2', 'category' => 'Gaming',         'cost' => 85,  'icon' => 'discord'],
            ['name' => 'Netflix',    'color' => '#E50914', 'category' => 'Entertainment',  'cost' => 175, 'icon' => 'netflix'],
        ];

        foreach ($services as $service) {
            Service::updateOrCreate(['name' => $service['name']], array_merge($service, ['is_active' => true]));
        }
    }
}
