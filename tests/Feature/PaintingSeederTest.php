<?php

use App\Models\Component;
use App\Models\Product;
use App\Models\ProductOption;
use App\Services\Costing;
use Database\Seeders\CategorySeeder;
use Database\Seeders\PaintingComponentSeeder;
use Database\Seeders\PaintingKitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([CategorySeeder::class, PaintingComponentSeeder::class, PaintingKitSeeder::class]);
});

test('components carry the cost sheet figures', function () {
    $costing = app(Costing::class);
    $cost = fn (string $code) => $costing->componentUnitCost(Component::query()->where('code', $code)->sole());

    expect($cost('FIG-VEH'))->toEqualWithDelta(40, 0.01)
        ->and($cost('FIG-FRU'))->toEqualWithDelta(42.75 / 4, 0.01)
        ->and($cost('PAINT-POT'))->toEqualWithDelta(20.33, 0.01)
        ->and($cost('KIT-ASM'))->toEqualWithDelta(14, 0.01)
        ->and($cost('PCK-SMALL'))->toEqualWithDelta(103, 0.01)
        ->and($cost('PCK-COURIER'))->toEqualWithDelta(33, 0.01)
        ->and(Component::query()->where('code', 'FIG-VEH')->sole()->keeps_stock)->toBeTrue();
});

test('the price list becomes one product per theme with costed options', function () {
    $option = ProductOption::query()->where('code', 'VEH-1F-1P-1B')->sole();

    expect(Product::query()->count())->toBe(7)
        ->and(ProductOption::query()->count())->toBe(70)
        ->and($option->retail_price)->toEqual(461.67)
        ->and($option->product->name)->toBe('Vehicle Kit 🚗')
        ->and($option->name)->toBe('1 Figure, 1 Pot, 1 Brush · Choose 1 of 6')
        ->and(app(Costing::class)->optionCost($option))->toEqualWithDelta(28.50 + 40 + 20.33 + 30 + 14 + 103, 0.01)
        ->and(ProductOption::query()->where('code', 'ALP-26F-6P-1B')->sole()->retail_price)->toEqual(2717.0);
});

test('running the seeders again updates instead of duplicating', function () {
    $this->seed([PaintingComponentSeeder::class, PaintingKitSeeder::class]);

    expect(ProductOption::query()->count())->toBe(70)
        ->and(ProductOption::query()->where('code', 'VEH-1F-1P-1B')->sole()->parts()->count())->toBe(6);
});
