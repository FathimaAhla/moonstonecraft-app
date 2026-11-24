<?php

namespace Database\Factories;

use App\Models\PackRecord;
use App\Models\ProductOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PackRecord>
 */
class PackRecordFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_option_id' => ProductOption::factory(),
            'packed_on' => now()->toDateString(),
            'qty' => 1,
            'qty_remaining' => 1,
            'is_legacy' => true,
        ];
    }
}
