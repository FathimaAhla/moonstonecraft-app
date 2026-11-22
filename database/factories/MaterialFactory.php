<?php

namespace Database\Factories;

use App\Models\Material;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Material>
 */
class MaterialFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
            'unit' => 'g',
            'type' => 'material',
            'stock' => 1000,
            'unit_cost' => 1,
            'reorder_level' => 0,
        ];
    }
}
