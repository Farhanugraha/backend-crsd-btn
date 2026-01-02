<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => Hash::make('password123'),
            'phone' => '08123456789',
            'divisi' => 'IT',
            'unit_kerja' => 'Backend',
            'role' => 'admin',
            'email_verified_at' => now(),
        ]);

        User::create([
            'name' => 'SuperAdmin User',
            'email' => 'superadmin@example.com',
            'password' => Hash::make('password123'),
            'phone' => '08987654321',
            'divisi' => 'Management',
            'unit_kerja' => 'Leadership',
            'role' => 'superadmin',
            'email_verified_at' => now(),
        ]);

        User::create([
            'name' => 'Regular User',
            'email' => 'user@example.com',
            'password' => Hash::make('password123'),
            'phone' => '08111222333',
            'divisi' => 'Sales',
            'unit_kerja' => 'Marketing',
            'role' => 'user',
            'email_verified_at' => now(),
        ]);
    }
}