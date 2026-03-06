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
            'name' => 'SuperAdmin',
            'email' => 'superadmin@example.com',
            'password' => Hash::make('Password123!'),
            'phone' => '08987654321',
            'divisi' => 'Management',
            'unit_kerja' => 'Leadership',
            'role' => 'superadmin',
            'email_verified_at' => now(),
        ]);
    }
}