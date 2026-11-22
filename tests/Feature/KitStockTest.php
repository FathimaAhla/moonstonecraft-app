<?php

use App\Models\Component;
use App\Models\Material;
use App\Models\ProductOption;
use App\Models\Purchase;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\KitStockService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->paint = Material::factory()->create(['name' => 'Paint', 'unit' => 'ml', 'stock' => 1200, 'unit_cost' => 4]);
    $this->emptyPot = Material::factory()->create(['name' => 'Empty pot', 'stock' => 100, 'unit_cost' => 15]);
    $this->brush = Material::factory()->create(['name' => 'Brush', 'stock' => 200, 'unit_cost' => 12]);

    $this->car = Component::factory()->keepsStock(0)->create(['code' => 'VEH-CAR', 'name' => 'Car']);
    $this->pot = Component::factory()->keepsStock(0)->create(['code' => 'PAINT-POT', 'name' => 'Paint pot']);
    $stage = $this->pot->stages()->create(['name' => 'Fill']);
    $stage->lines()->create(['material_id' => $this->paint->id, 'qty' => 10]);
    $stage->lines()->create(['material_id' => $this->emptyPot->id, 'qty' => 1]);

    $this->kit = ProductOption::factory()->create(['code' => 'VEH-1F-1P-1B', 'retail_price' => 650]);
    $this->kit->parts()->create(['component_id' => $this->car->id, 'qty' => 1]);
    $this->kit->parts()->create(['component_id' => $this->pot->id, 'qty' => 1]);
    $this->kit->parts()->create(['material_id' => $this->brush->id, 'qty' => 1]);

    $this->user = User::factory()->create();
});

test('adding made figures raises figure stock without touching materials', function () {
    $this->actingAs($this->user)->post(route('stock.make', $this->car), ['qty' => 50, 'made_on' => '2026-11-03', 'use_materials' => true])->assertRedirect();

    expect($this->car->fresh()->stock)->toEqual(50.0)
        ->and(StockMovement::query()->where('component_id', $this->car->id)->sole()->reason)->toBe('made');
});

test('making paint pots uses paint and empty pots from the recipe', function () {
    app(KitStockService::class)->make($this->pot, 85);

    expect($this->pot->fresh()->stock)->toEqual(85.0)
        ->and($this->paint->fresh()->stock)->toEqual(350.0)
        ->and($this->emptyPot->fresh()->stock)->toEqual(15.0)
        ->and(app(KitStockService::class)->canMakeComponent($this->pot->fresh()))->toBe(['can_make' => 15, 'limit' => 'Empty pot']);
});

test('a part that does not keep stock cannot be made', function () {
    $this->car->update(['keeps_stock' => false]);

    $this->actingAs($this->user)->post(route('stock.make', $this->car), ['qty' => 5, 'made_on' => '2026-11-03']);

    expect($this->car->fresh()->stock)->toEqual(0.0);
});

test('packing kits moves parts into ready kit stock and unpacking reverses it', function () {
    $kits = app(KitStockService::class);
    $kits->make($this->car, 50, useMaterials: false);
    $kits->make($this->pot, 85);

    $this->actingAs($this->user)->post(route('stock.pack', $this->kit), ['qty' => 10, 'packed_on' => '2026-11-10'])->assertRedirect();

    expect($this->kit->fresh()->stock)->toEqual(10.0)
        ->and($this->car->fresh()->stock)->toEqual(40.0)
        ->and($this->pot->fresh()->stock)->toEqual(75.0)
        ->and($this->brush->fresh()->stock)->toEqual(190.0);

    $this->actingAs($this->user)->post(route('stock.unpack', $this->kit), ['qty' => 4, 'packed_on' => '2026-11-11'])->assertRedirect();

    expect($this->kit->fresh()->stock)->toEqual(6.0)
        ->and($this->car->fresh()->stock)->toEqual(44.0)
        ->and($this->brush->fresh()->stock)->toEqual(194.0);
});

test('unpacking more kits than are packed is refused', function () {
    $this->actingAs($this->user)->post(route('stock.unpack', $this->kit), ['qty' => 1, 'packed_on' => '2026-11-11']);

    expect($this->kit->fresh()->stock)->toEqual(0.0)
        ->and($this->car->fresh()->stock)->toEqual(0.0);
});

test('can make reports the part that runs out first', function () {
    $kits = app(KitStockService::class);
    $kits->make($this->car, 6, useMaterials: false);
    $kits->make($this->pot, 85);

    expect($kits->canMakeOption($this->kit->fresh()))->toBe(['can_make' => 6, 'limit' => 'VEH-CAR Car']);
});

test('a sale uses ready kits first, then packs the rest from parts, and deleting restores both', function () {
    $kits = app(KitStockService::class);
    $kits->make($this->car, 20, useMaterials: false);
    $kits->make($this->pot, 20);
    $kits->pack($this->kit, 2);

    $sale = app(SaleService::class)->record([
        'sold_on' => '2026-11-15', 'sale_type' => 'retail', 'payment_method' => 'cash', 'delivery_method' => 'pickup',
        'items' => [['product_option_id' => $this->kit->id, 'qty' => 3, 'unit_price' => 650]],
    ]);

    expect($this->kit->fresh()->stock)->toEqual(0.0)
        ->and($this->car->fresh()->stock)->toEqual(17.0)
        ->and($this->pot->fresh()->stock)->toEqual(17.0)
        ->and($this->brush->fresh()->stock)->toEqual(197.0);

    app(SaleService::class)->delete($sale);

    expect($this->kit->fresh()->stock)->toEqual(2.0)
        ->and($this->car->fresh()->stock)->toEqual(18.0)
        ->and($this->brush->fresh()->stock)->toEqual(198.0);
});

test('damage on a part is recorded with its reason', function () {
    app(KitStockService::class)->make($this->car, 10, useMaterials: false);

    $this->actingAs($this->user)->post(route('stock.adjust-component', $this->car), ['qty' => -2, 'reason' => 'damaged', 'note' => 'cracked'])->assertRedirect();

    $movement = StockMovement::query()->where('component_id', $this->car->id)->latest('id')->first();

    expect($this->car->fresh()->stock)->toEqual(8.0)
        ->and($movement->reason)->toBe('damaged')
        ->and($movement->note)->toBe('cracked');
});

test('a purchase saves its receipt privately and shows it back', function () {
    Storage::fake('local');

    $this->actingAs($this->user)->post(route('purchases.store'), [
        'purchased_on' => '2026-11-02',
        'lines' => [['material_id' => $this->paint->id, 'qty' => 1000, 'line_cost' => 4000]],
        'receipt' => UploadedFile::fake()->image('bill.jpg'),
    ])->assertRedirect();

    $purchase = Purchase::query()->sole();
    Storage::disk('local')->assertExists($purchase->receipt_path);

    $this->actingAs($this->user)->get(route('purchases.receipt', $purchase))->assertOk();
    $this->actingAs($this->user)->delete(route('purchases.destroy', $purchase));

    Storage::disk('local')->assertMissing($purchase->receipt_path);
});
