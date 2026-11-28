<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::query()->firstOrCreate(
            ['email' => 'admin@moonstonecraft.lk'],
            ['name' => 'Admin', 'password' => 'password', 'email_verified_at' => now(), 'role' => 'admin'],
        );

        $this->call([CategorySeeder::class, SampleDataSeeder::class, PaintingComponentSeeder::class, PaintingKitSeeder::class, FigureDesignSeeder::class, ExpenseSheetPurchaseSeeder::class]);
    }
}
