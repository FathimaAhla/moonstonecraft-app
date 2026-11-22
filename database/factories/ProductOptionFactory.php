<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductOption>
 */
class ProductOptionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'code' => strtoupper(fake()->unique()->bothify('???-???-###')),
            'name' => fake()->words(2, true),
            'retail_price' => 1000,
            'wholesale_price' => null,
            'courier_fee_estimate' => 0,
            'active' => true,
        ];
    }
}
