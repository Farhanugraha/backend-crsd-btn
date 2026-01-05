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
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password123'),
            'phone' => '08123456789',
            'divisi' => 'IT',
            'unit_kerja' => 'Backend',
            'role' => 'admin',
            'email_verified_at' => now(),
        ]);

          User::create([
            'name' => 'Admin 2',
            'email' => 'admin2@example.com',
            'password' => Hash::make('password123'),
            'phone' => '08123456788',
            'divisi' => 'Information Technology',
            'unit_kerja' => 'Backend Developer',
            'role' => 'admin',
            'email_verified_at' => now(),
        ]);

        User::create([
            'name' => 'SuperAdmin',
            'email' => 'superadmin@example.com',
            'password' => Hash::make('password123'),
            'phone' => '08987654321',
            'divisi' => 'Management',
            'unit_kerja' => 'Leadership',
            'role' => 'superadmin',
            'email_verified_at' => now(),
        ]);

         User::create([
            'name' => 'SuperAdmin 2',
            'email' => 'superadmin2@example.com',
            'password' => Hash::make('password123'),
            'phone' => '08987654323',
            'divisi' => 'CRSD',
            'unit_kerja' => 'Corporate Social Responsibility and Sustainability',
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

        User::create([
            'name' => 'Farhan Nugraha',
            'email' => 'user2@example.com',
            'password' => Hash::make('password123'),
            'phone' => '08111222345',
            'divisi' => 'Information Technology',
            'unit_kerja' => 'Software Development',
            'role' => 'user',
            'email_verified_at' => now(),
        ]);
    }
}