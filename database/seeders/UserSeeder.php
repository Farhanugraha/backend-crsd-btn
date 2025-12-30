<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Faker\Factory as FakerFactory;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Reset unique state dari Faker
        $faker = FakerFactory::create();
        $faker->unique(true);

        // Generate 50 user
        User::factory()->count(50)->create();
    }
}