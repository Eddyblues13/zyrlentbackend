<?php

namespace Database\Seeders;

use App\Models\Admin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        Admin::updateOrCreate(
            ['email' => 'admin@zyrlent.com'],
            [
                'name' => 'Super Admin',
                'email' => 'admin@zyrlent.com',
                'password' => Hash::make('password'),
                'is_super' => true,
            ]
        );
    }
}
