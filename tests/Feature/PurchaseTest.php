<?php

use App\Models\Expense;
use App\Models\Material;
use App\Models\Purchase;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\PurchaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the worked example gives the documented landed costs and totals', function () {
    $plaster = Material::factory()->create(['stock' => 0, 'unit_cost' => 0]);
    $polythene = Material::factory()->create(['stock' => 0, 'unit_cost' => 0]);

    $this->actingAs(User::factory()->create())->post(route('purchases.store'), [
        'purchased_on' => '2026-11-20',
        'transport_cost' => 300,
        'refreshment_cost' => 150,
        'other_cost' => 0,
        'refreshment_as_stock_cost' => false,
        'lines' => [
            ['material_id' => $plaster->id, 'qty' => 5000, 'line_cost' => 1600],
            ['material_id' => $polythene->id, 'qty' => 100, 'line_cost' => 800],
        ],
    ])->assertRedirect();

    $purchase = Purchase::query()->with('items')->sole();

    expect($purchase->purchase_no)->toBe('PUR-2026-0001')
        ->and($purchase->items_total)->toEqual(2400.0)
        ->and($purchase->grand_total)->toEqual(2850.0)
        ->and($purchase->items->sum('extra_allocated'))->toEqualWithDelta(300, 0.01)
        ->and($plaster->fresh()->unit_cost)->toEqualWithDelta(0.36, 0.00001)
        ->and($plaster->fresh()->stock)->toEqual(5000.0)
        ->and($polythene->fresh()->unit_cost)->toEqualWithDelta(9.0, 0.00001)
        ->and(Expense::query()->sole()->amount)->toEqual(150.0)
        ->and(StockMovement::query()->where('material_id', $plaster->id)->sum('qty'))->toEqual(5000);
});

test('refreshment folded into stock cost creates no expense', function () {
    $plaster = Material::factory()->create(['stock' => 0]);

    app(PurchaseService::class)->record(
        ['purchased_on' => '2026-11-20', 'transport_cost' => 0, 'refreshment_cost' => 100, 'refreshment_as_stock_cost' => true],
        [['material_id' => $plaster->id, 'qty' => 1000, 'line_cost' => 400]],
    );

    expect(Expense::query()->count())->toBe(0)
        ->and($plaster->fresh()->unit_cost)->toEqualWithDelta(0.5, 0.00001);
});

test('purchases update the weighted average cost', function () {
    $plaster = Material::factory()->create(['stock' => 1000, 'unit_cost' => 0.30]);

    app(PurchaseService::class)->record(['purchased_on' => '2026-11-20'], [['material_id' => $plaster->id, 'qty' => 1000, 'line_cost' => 400]]);

    expect($plaster->fresh()->unit_cost)->toEqualWithDelta(0.35, 0.00001)
        ->and($plaster->fresh()->stock)->toEqual(2000.0);
});

test('deleting a purchase reverses stock and its expense', function () {
    $plaster = Material::factory()->create(['stock' => 0]);
    $purchase = app(PurchaseService::class)->record(
        ['purchased_on' => '2026-11-20', 'refreshment_cost' => 50],
        [['material_id' => $plaster->id, 'qty' => 1000, 'line_cost' => 400]],
    );

    $this->actingAs(User::factory()->create())->delete(route('purchases.destroy', $purchase))->assertRedirect();

    expect($plaster->fresh()->stock)->toEqual(0.0)
        ->and(Expense::query()->count())->toBe(0)
        ->and((float) StockMovement::query()->where('material_id', $plaster->id)->sum('qty'))->toEqual(0.0);
});

test('a material used in a purchase cannot be deleted', function () {
    $plaster = Material::factory()->create(['stock' => 0]);
    app(PurchaseService::class)->record(['purchased_on' => '2026-11-20'], [['material_id' => $plaster->id, 'qty' => 10, 'line_cost' => 10]]);

    $this->actingAs(User::factory()->create())->delete(route('materials.destroy', $plaster));

    expect($plaster->fresh())->not->toBeNull();
});
