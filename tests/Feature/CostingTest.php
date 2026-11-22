<?php

use App\Models\Component;
use App\Models\Material;
use App\Models\ProductOption;
use App\Services\Costing;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeFigure(): array
{
    $plaster = Material::factory()->create(['unit_cost' => 0.35, 'stock' => 10000]);
    $labor = Material::factory()->create(['type' => 'overhead', 'unit_cost' => 100, 'stock' => 0]);
    $figure = Component::factory()->create(['yield_qty' => 10, 'wastage_pct' => 10]);
    $figure->stages()->create(['name' => 'Mixing', 'sort_order' => 1])->lines()->create(['material_id' => $plaster->id, 'qty' => 200]);
    $figure->stages()->create(['name' => 'Sanding', 'sort_order' => 2])->lines()->create(['material_id' => $labor->id, 'qty' => 0.5]);

    return [$figure, $plaster, $labor];
}

test('product unit cost is built stage by stage with wastage', function () {
    [$figure] = makeFigure();
    $breakdown = app(Costing::class)->componentBreakdown($figure);

    // Mixing 70 + Sanding 50 = 120 per batch; /10 = 12; +10% = 13.20
    expect($breakdown['stages'][0]['running'])->toEqual(70.0)
        ->and($breakdown['batch'])->toEqual(120.0)
        ->and($breakdown['unit'])->toEqualWithDelta(13.2, 0.0001);
});

test('option cost adds parts and courier, and suggestions never go below cost', function () {
    [$figure, $plaster] = makeFigure();
    $option = ProductOption::factory()->create(['courier_fee_estimate' => 300]);
    $option->parts()->create(['component_id' => $figure->id, 'qty' => 5]);
    $option->parts()->create(['material_id' => $plaster->id, 'qty' => 10]);
    $costing = app(Costing::class);

    expect($costing->optionCost($option))->toEqualWithDelta(69.5, 0.0001)
        ->and($costing->optionCostWithCourier($option))->toEqualWithDelta(369.5, 0.0001);

    foreach ($costing->suggest($option)['rows'] as $row) {
        expect($row['price'])->toBeGreaterThanOrEqual(69.5)
            ->and($row['price_courier'])->toBeGreaterThanOrEqual(369.5);
    }
});

test('usage skips overheads and includes planned wastage', function () {
    [$figure, $plaster, $labor] = makeFigure();
    $usage = app(Costing::class)->componentUsage($figure, 3);

    // 200 g per 10 units, +10% => 22 g per unit => 66 g for 3
    expect($usage)->toHaveKey($plaster->id)
        ->and($usage[$plaster->id])->toEqualWithDelta(66, 0.0001)
        ->and($usage)->not->toHaveKey($labor->id);
});
