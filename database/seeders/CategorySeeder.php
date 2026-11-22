<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /**
     * Seed the five crafts.
     */
    public function run(): void
    {
        $categories = [
            ['DIY painting kit', 'PNT'],
            ['Pipe cleaner craft', 'PIP'],
            ['Gypsum pot', 'GYP'],
            ['Candle', 'CND'],
            ['Resin', 'RSN'],
        ];

        foreach ($categories as $sort => [$name, $prefix]) {
            Category::query()->updateOrCreate(['code_prefix' => $prefix], ['name' => $name, 'sort' => $sort]);
        }
    }
}
