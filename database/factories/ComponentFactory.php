<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Component;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Component>
 */
class ComponentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_id' => Category::factory(),
            'code' => strtoupper(fake()->unique()->bothify('???-???-###')),
            'name' => fake()->words(2, true),
            'yield_qty' => 1,
            'wastage_pct' => 0,
            'active' => true,
        ];
    }

    /**
     * A part you make in advance (figure, paint pot) that keeps its own stock.
     */
    public function keepsStock(float $stock = 0): static
    {
        return $this->state(fn () => ['keeps_stock' => true, 'stock' => $stock, 'prededucted_stock' => $stock]);
    }
}
