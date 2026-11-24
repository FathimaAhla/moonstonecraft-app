<?php

use App\Models\Component;
use App\Models\Material;
use App\Models\PackRecord;
use App\Models\ProductOption;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Costing;
use App\Services\KitStockService;
use App\Services\PurchaseService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function materialPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Thing', 'unit' => 'pc', 'is_purchasable' => true, 'tracks_stock' => true,
        'cost_source' => 'purchase', 'opening_unit_cost' => 5,
    ], $overrides);
}

function buy(Material $material, float $qty, float $cost, array $line = [], array $header = []): void
{
    app(PurchaseService::class)->record($header + ['purchased_on' => '2026-11-01'], [['material_id' => $material->id, 'qty' => $qty, 'line_cost' => $cost] + $line]);
}

function sellOption(ProductOption $option, float $qty): Sale
{
    return app(SaleService::class)->record([
        'sold_on' => '2026-11-15', 'sale_type' => 'retail', 'payment_method' => 'cash', 'delivery_method' => 'pickup',
        'items' => [['product_option_id' => $option->id, 'qty' => $qty, 'unit_price' => 100]],
    ]);
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->tracked = Material::factory()->create(['name' => 'Tracked', 'stock' => 100, 'unit_cost' => 2]);
    $this->manual = Material::factory()->create(['name' => 'Manual', 'tracks_stock' => false, 'stock' => 0, 'unit_cost' => 10]);
    $this->cost = Material::factory()->create(['name' => 'Labor', 'type' => 'overhead', 'unit_cost' => 50]);

    $this->part = Component::factory()->create(['code' => 'PART-1', 'name' => 'Part']);
    $stage = $this->part->stages()->create(['name' => 'Make']);
    $stage->lines()->create(['material_id' => $this->tracked->id, 'qty' => 4]);
    $stage->lines()->create(['material_id' => $this->manual->id, 'qty' => 1]);
    $stage->lines()->create(['material_id' => $this->cost->id, 'qty' => 1]);

    $this->kit = ProductOption::factory()->create(['code' => 'KIT-1']);
    $this->kit->parts()->create(['component_id' => $this->part->id, 'qty' => 1]);
    $this->kit->parts()->create(['material_id' => $this->tracked->id, 'qty' => 1]);
});

test('all three material behaviours can be saved and incompatible ones are refused', function () {
    $this->actingAs($this->user);

    $this->post(route('materials.store'), materialPayload(['name' => 'A', 'stock' => 5]))->assertSessionDoesntHaveErrors();
    $this->post(route('materials.store'), materialPayload(['name' => 'B', 'tracks_stock' => false]))->assertSessionDoesntHaveErrors();
    $this->post(route('materials.store'), materialPayload(['name' => 'C', 'is_purchasable' => false, 'tracks_stock' => false, 'cost_source' => 'manual', 'manual_unit_cost' => 7]))->assertSessionDoesntHaveErrors();

    expect(Material::query()->where('name', 'A')->sole())->tracks_stock->toBeTrue()->stock->toEqual(5.0)
        ->and(Material::query()->where('name', 'B')->sole())->is_purchasable->toBeTrue()->tracks_stock->toBeFalse()
        ->and(Material::query()->where('name', 'C')->sole())->is_purchasable->toBeFalse()->unit_cost->toEqual(7.0);

    $this->post(route('materials.store'), materialPayload(['name' => 'D', 'is_purchasable' => false, 'tracks_stock' => true, 'cost_source' => 'manual', 'manual_unit_cost' => 1]))->assertSessionHasErrors('tracks_stock');
    $this->post(route('materials.store'), materialPayload(['name' => 'E', 'is_purchasable' => false, 'tracks_stock' => false]))->assertSessionHasErrors('cost_source');
    $this->post(route('materials.store'), materialPayload(['name' => 'F', 'cost_source' => 'manual']))->assertSessionHasErrors('manual_unit_cost');
});

test('a manual cost stays active across purchases and the user can switch back', function () {
    $this->actingAs($this->user);
    $this->patch(route('materials.cost', $this->tracked), ['cost_source' => 'manual', 'manual_unit_cost' => 9])->assertSessionDoesntHaveErrors();

    buy($this->tracked, 100, 400);
    $fresh = $this->tracked->fresh();

    expect($fresh->unit_cost)->toEqual(9.0)
        ->and($fresh->manual_unit_cost)->toEqual(9.0)
        ->and($fresh->purchase_unit_cost)->toEqualWithDelta(3.0, 0.0001);

    $this->patch(route('materials.cost', $this->tracked), ['cost_source' => 'purchase']);

    expect($this->tracked->fresh()->unit_cost)->toEqualWithDelta(3.0, 0.0001)
        ->and($this->tracked->fresh()->manual_unit_cost)->toEqual(9.0);
});

test('purchase units convert to recipe units with landed cost, and cost-only items cannot be bought', function () {
    $pcs = Material::factory()->create(['unit' => 'pc', 'stock' => 0, 'unit_cost' => 0]);

    buy($pcs, 2, 240, ['purchase_unit' => 'pack', 'conversion_factor' => 12], ['transport_cost' => 24]);
    $item = $pcs->purchaseItems()->sole();

    expect($pcs->fresh()->stock)->toEqual(24.0)
        ->and($item->converted_qty)->toEqual(24.0)
        ->and($item->landed_unit_cost)->toEqualWithDelta(11.0, 0.0001)
        ->and($pcs->fresh()->unit_cost)->toEqualWithDelta(11.0, 0.0001);

    $this->actingAs($this->user)->post(route('purchases.store'), [
        'purchased_on' => '2026-11-02', 'lines' => [['material_id' => $this->cost->id, 'qty' => 1, 'line_cost' => 10]],
    ])->assertSessionHasErrors('lines.0.material_id');
});

test('a purchasable item with manual stock records purchases and cost but no stock', function () {
    buy($this->manual, 10, 200);
    buy($this->manual, 10, 400);

    expect($this->manual->fresh()->purchase_unit_cost)->toEqualWithDelta(40.0, 0.0001)
        ->and(StockMovement::query()->where('material_id', $this->manual->id)->count())->toBe(0)
        ->and($this->manual->fresh()->stock)->toEqual(0.0);

    $purchase = $this->manual->purchaseItems()->latest('id')->first()->purchase;
    app(PurchaseService::class)->delete($purchase);

    expect($this->manual->fresh()->purchase_unit_cost)->toEqualWithDelta(20.0, 0.0001);
});

test('creating and copying a component never moves stock and the copy is independent', function () {
    $this->actingAs($this->user);
    $before = StockMovement::query()->count();

    $this->post(route('components.copy', $this->part), ['code' => 'PART-2', 'name' => 'Part B'])->assertRedirect();
    $this->post(route('components.copy', $this->part), ['code' => 'PART-1'])->assertSessionHasErrors('code');

    $copy = Component::query()->where('code', 'PART-2')->sole();
    $copy->stages->first()->lines->first()->update(['qty' => 99]);

    expect(StockMovement::query()->count())->toBe($before)
        ->and($this->tracked->fresh()->stock)->toEqual(100.0)
        ->and($copy->name)->toBe('Part B')
        ->and($copy->stock)->toEqual(0.0)
        ->and($copy->stages)->toHaveCount(1)
        ->and($this->part->stages->first()->lines->first()->qty)->toEqual(4.0);
});

test('a material can be added from the recipe screen and used straight away', function () {
    $this->actingAs($this->user);
    $stage = $this->part->stages->first();

    $this->post(route('materials.store'), materialPayload(['name' => 'Fresh item', 'tracks_stock' => false]))->assertRedirect();
    $new = Material::query()->where('name', 'Fresh item')->sole();

    $this->post(route('stage-lines.store', $stage), ['material_id' => $new->id, 'qty' => 2])->assertRedirect();
    $this->get(route('components.show', $this->part))->assertOk()
        ->assertInertia(fn ($page) => $page->where('materials', fn ($m) => collect($m)->contains('id', $new->id)));

    expect($stage->lines()->where('material_id', $new->id)->exists())->toBeTrue();
});

test('line wastage raises only that line and usage only counts tracked items', function () {
    $costing = app(Costing::class);
    $line = $this->part->stages->first()->lines->firstWhere('material_id', $this->tracked->id);
    $base = $costing->componentUnitCost($this->part->fresh());

    $this->actingAs($this->user)->put(route('stage-lines.update', $line), ['material_id' => $this->tracked->id, 'qty' => 4, 'wastage_pct' => 50])->assertRedirect();

    expect($costing->componentUnitCost($this->part->fresh()))->toEqualWithDelta($base + 4 * 0.5 * 2, 0.0001)
        ->and($costing->componentUsage($this->part->fresh(), 1))->toBe([$this->tracked->id => 6.0]);
});

test('packing deducts tracked items only and saves a record', function () {
    $record = app(KitStockService::class)->pack($this->kit, 2);

    expect($this->tracked->fresh()->stock)->toEqual(100 - 2 * 5.0)
        ->and($this->manual->fresh()->stock)->toEqual(0.0)
        ->and($this->kit->fresh()->stock)->toEqual(2.0)
        ->and($record->contents)->not->toBeEmpty()
        ->and($record->usage['materials'])->toHaveKey($this->tracked->id)
        ->and($record->usage['materials'])->not->toHaveKey($this->manual->id);
});

test('selling packed kits does not deduct materials again, and the rest is deducted once', function () {
    app(KitStockService::class)->pack($this->kit, 2);
    $sale = sellOption($this->kit, 3);
    $item = $sale->items->first();

    expect($this->kit->fresh()->stock)->toEqual(0.0)
        ->and($this->tracked->fresh()->stock)->toEqual(100 - 10 - 5.0)
        ->and($item->contents['packed_qty'])->toEqual(2.0)
        ->and($item->contents['unpacked_qty'])->toEqual(1.0)
        ->and($item->allocations)->toHaveCount(1);
});

test('a custom kit sale stores its own contents and deducts tracked usage', function () {
    $sale = app(SaleService::class)->record([
        'sold_on' => '2026-11-15', 'sale_type' => 'retail', 'payment_method' => 'cash', 'delivery_method' => 'pickup',
        'items' => [['custom' => true, 'name' => 'Birthday set', 'qty' => 2, 'unit_price' => 300, 'parts' => [
            ['type' => 'material', 'id' => $this->tracked->id, 'qty' => 3],
            ['type' => 'material', 'id' => $this->manual->id, 'qty' => 1],
        ]]],
    ]);
    $item = $sale->items->first();

    expect($item->is_custom)->toBeTrue()->and($item->product_option_id)->toBeNull()
        ->and($this->tracked->fresh()->stock)->toEqual(94.0)
        ->and($this->manual->fresh()->stock)->toEqual(0.0)
        ->and($item->unit_cost)->toEqual(16.0);

    $this->tracked->update(['unit_cost' => 500]);
    app(SaleService::class)->delete($sale);

    expect($this->tracked->fresh()->stock)->toEqual(100.0);
});

test('unpacking restores saved contents after recipes change, partially, and never more than packed', function () {
    $kits = app(KitStockService::class);
    $kits->pack($this->kit, 4);
    $this->kit->parts()->delete();
    $this->kit->parts()->create(['material_id' => $this->manual->id, 'qty' => 1]);
    $this->tracked->update(['unit_cost' => 99]);

    $result = $kits->unpack($this->kit->fresh(), 1);

    expect($result)->toBe(['restored_qty' => 1.0, 'unknown_qty' => 0.0])
        ->and($this->tracked->fresh()->stock)->toEqual(100 - 20 + 5.0);

    $kits->unpack($this->kit->fresh(), 3);
    expect($this->tracked->fresh()->stock)->toEqual(100.0);

    expect(fn () => $kits->unpack($this->kit->fresh(), 1))->toThrow(InvalidArgumentException::class);
});

test('reversing a sale restores saved usage after the recipe changed', function () {
    $kits = app(KitStockService::class);
    $kits->pack($this->kit, 1);
    $sale = sellOption($this->kit, 2);

    expect($this->tracked->fresh()->stock)->toEqual(100 - 10.0);

    $this->kit->parts()->delete();
    app(SaleService::class)->delete($sale);

    expect($this->tracked->fresh()->stock)->toEqual(95.0)
        ->and($this->kit->fresh()->stock)->toEqual(1.0)
        ->and(PackRecord::query()->where('product_option_id', $this->kit->id)->sum('qty_remaining'))->toEqual(1.0);
});

test('legacy packed stock without a snapshot is reported and nothing is invented', function () {
    $this->kit->update(['stock' => 3]);

    $result = app(KitStockService::class)->unpack($this->kit->fresh(), 2);

    expect($result)->toBe(['restored_qty' => 0.0, 'unknown_qty' => 2.0])
        ->and($this->tracked->fresh()->stock)->toEqual(100.0)
        ->and($this->kit->fresh()->stock)->toEqual(1.0);

    $this->actingAs($this->user)->post(route('stock.unpack', $this->kit), ['qty' => 1, 'packed_on' => '2026-11-11'])->assertRedirect();
    expect(PackRecord::query()->where('is_legacy', true)->exists())->toBeTrue();
});

test('existing component stock is already paid for and is not deducted twice when packed', function () {
    $made = Component::factory()->keepsStock(5)->create();
    $made->stages()->create(['name' => 'S'])->lines()->create(['material_id' => $this->tracked->id, 'qty' => 10]);
    $option = ProductOption::factory()->create();
    $option->parts()->create(['component_id' => $made->id, 'qty' => 1]);

    app(KitStockService::class)->pack($option, 7);

    expect($made->fresh()->stock)->toEqual(-2.0)
        ->and($this->tracked->fresh()->stock)->toEqual(100 - 20.0)
        ->and($made->fresh()->prededucted_stock)->toEqual(0.0);
});

test('legacy overheads keep their meaning as cost-only manual items', function () {
    expect($this->cost->fresh())
        ->is_purchasable->toBeFalse()
        ->tracks_stock->toBeFalse()
        ->cost_source->toBe('manual')
        ->unit_cost->toEqual(50.0);
});
