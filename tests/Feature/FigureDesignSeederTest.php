<?php

use App\Models\Component;
use App\Models\Product;
use App\Services\FigureService;
use Database\Seeders\CategorySeeder;
use Database\Seeders\FigureDesignSeeder;
use Database\Seeders\PaintingComponentSeeder;
use Database\Seeders\PaintingKitSeeder;
use Database\Seeders\SampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the painting kit figures are seeded into their kits once, with the kit figure making steps', function () {
    $this->seed([CategorySeeder::class, SampleDataSeeder::class, PaintingComponentSeeder::class, PaintingKitSeeder::class, FigureDesignSeeder::class]);
    $this->seed(FigureDesignSeeder::class);

    $names = fn (string $code) => app(FigureService::class)->designs(Product::query()->where('code', $code)->sole()->id)->pluck('name')->sort()->values()->all();

    expect($names('PNT-VEH'))->toBe(['Bike', 'Bus', 'Car', 'Helicopter', 'Lorry', 'Tractor'])
        ->and($names('PNT-AST'))->toBe(['Astronaut', 'Earth', 'Rocket'])
        ->and($names('PNT-CUT'))->toBe(['Bear', 'Bunny', 'Butterfly'])
        ->and($names('PNT-FRU'))->toBe(['Orange', 'Pineapple', 'Strawberry', 'Watermelon'])
        ->and($names('PNT-ALP'))->toHaveCount(26)
        ->and($names('PNT-FLW'))->toBe([]);

    $car = Component::query()->where('name', 'Car')->sole();
    expect($car->stages()->count())->toBe(Component::query()->where('code', 'FIG-VEH')->sole()->stages()->count());
});
