<?php

use App\Models\Component;
use App\Models\FigureCombo;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\Sale;
use App\Models\SaleItemAssignment;
use App\Models\User;
use App\Services\FigureService;
use App\Services\PaintSetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->actingAs(User::factory()->create());

    $this->red = Material::factory()->create(['name' => 'Paint - Red', 'unit' => 'ml', 'stock' => 300, 'unit_cost' => 2, 'kit_group' => 'paint', 'colour' => '#ff0000']);
    $this->yellow = Material::factory()->create(['name' => 'Paint - Yellow', 'unit' => 'ml', 'stock' => 300, 'unit_cost' => 2, 'kit_group' => 'paint', 'colour' => '#ffff00']);
    $this->blue = Material::factory()->create(['name' => 'Paint - Blue', 'unit' => 'ml', 'stock' => 300, 'unit_cost' => 2, 'kit_group' => 'paint', 'colour' => '#0000ff']);
    $this->pot = Material::factory()->create(['name' => 'Empty pot', 'unit' => 'pc', 'stock' => 100, 'unit_cost' => 1]);
    $this->brush = Material::factory()->create(['name' => 'Brush', 'unit' => 'pc', 'stock' => 50, 'unit_cost' => 10]);

    $this->set = app(PaintSetService::class)->create([$this->red->id, $this->yellow->id, $this->blue->id], 3, $this->pot->id);

    $this->vehicleKit = Product::factory()->create(['name' => 'Vehicle Kit']);
    $figures = app(FigureService::class);
    $this->car = $figures->createDesign($this->vehicleKit, 'Car');
    $this->bike = $figures->createDesign($this->vehicleKit, 'Bike');
    $this->bus = $figures->createDesign($this->vehicleKit, 'Bus');
    $this->helicopter = $figures->createDesign($this->vehicleKit, 'Helicopter');
    Component::query()->whereIn('id', [$this->car->id, $this->bike->id, $this->bus->id, $this->helicopter->id])->update(['stock' => 10]);

    $this->carBikeBus = $figures->combo([$this->car->id => 1, $this->bike->id => 1, $this->bus->id => 1]);
    $this->heliBusBike = $figures->combo([$this->helicopter->id => 1, $this->bus->id => 1, $this->bike->id => 1]);

    $this->kit = ProductOption::factory()->for($this->vehicleKit)->create(['retail_price' => 1500, 'figures_per_kit' => 3, 'paint_pots_per_kit' => 3]);
    $this->kit->parts()->create(['material_id' => $this->brush->id, 'qty' => 1]);
});

function kitSale(array $assign = [], float $qty = 10): array
{
    return [
        'sold_on' => '2026-11-26',
        'sale_type' => 'retail',
        'payment_method' => 'cash',
        'delivery_method' => 'pickup',
        'items' => [['product_option_id' => test()->kit->id, 'qty' => $qty, 'unit_price' => 1500, 'assign' => $assign]],
    ];
}

test('a paint set is created from one colour per pot, plus the empty pots', function () {
    $this->post(route('paint-sets.store'), [
        'colours' => [$this->red->id, $this->red->id], 'ml_per_pot' => 4, 'pot_material_id' => $this->pot->id,
    ])->assertSessionHasNoErrors();

    $set = Component::query()->where('code', 'PS-002')->sole();
    $lines = $set->stages->flatMap->lines->pluck('qty', 'material_id');

    expect($set->name)->toBe('2-pot Red / Red')
        ->and($set->pot_colours)->toBe([$this->red->id, $this->red->id])
        ->and($lines[$this->red->id])->toEqual(8.0)
        ->and($lines[$this->pot->id])->toEqual(2.0);
});

test('a paint set must have 1, 2, 3, 4 or 6 pots', function () {
    $this->post(route('paint-sets.store'), [
        'colours' => array_fill(0, 5, $this->red->id), 'ml_per_pot' => 3,
    ])->assertSessionHasErrors('colours');

    expect(Component::query()->where('kit_group', 'paint')->count())->toBe(1);
});

test('making paint sets takes the paint now and counts single pots per colour', function () {
    $this->post(route('stock.make', $this->set), ['qty' => 20, 'made_on' => '2026-11-26'])->assertSessionHasNoErrors();
    $twoRed = app(PaintSetService::class)->create([$this->red->id, $this->red->id], 3);
    $this->post(route('stock.make', $twoRed), ['qty' => 5, 'made_on' => '2026-11-26']);

    expect($this->set->fresh()->stock)->toEqual(20.0)
        ->and($this->red->fresh()->stock)->toEqual(210.0)
        ->and($this->pot->fresh()->stock)->toEqual(40.0);

    $this->get(route('paint-sets.index'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('colours', fn ($colours) => collect($colours)->firstWhere('id', $this->red->id)['pots'] == 30
            && collect($colours)->firstWhere('id', $this->blue->id)['pots'] == 20));
});

test('figure combinations allow the same design twice and are saved once', function () {
    $this->post(route('figure-combos.store'), ['designs' => [$this->car->id => 2, $this->bus->id => 1]])->assertSessionHasNoErrors();
    $this->post(route('figure-combos.store'), ['designs' => [$this->bus->id => 1, $this->car->id => 2]])->assertSessionHasNoErrors();

    $combo = FigureCombo::query()->latest('id')->first();

    expect(FigureCombo::query()->count())->toBe(3)
        ->and($combo->name)->toBe('2 × Car + Bus')
        ->and($combo->size)->toBe(3);
});

test('selling 10 kits with some figure and paint rows takes only those, the rest waits', function () {
    $this->post(route('stock.make', $this->set), ['qty' => 20, 'made_on' => '2026-11-26']);

    $this->post(route('sales.store'), kitSale([
        'figures' => [['combo_id' => $this->carBikeBus->id, 'qty' => 2], ['combo_id' => $this->heliBusBike->id, 'qty' => 3]],
        'paint' => [['component_id' => $this->set->id, 'qty' => 4]],
    ]))->assertSessionHasNoErrors();

    expect($this->car->fresh()->stock)->toEqual(8.0)
        ->and($this->bike->fresh()->stock)->toEqual(5.0)
        ->and($this->bus->fresh()->stock)->toEqual(5.0)
        ->and($this->helicopter->fresh()->stock)->toEqual(7.0)
        ->and($this->set->fresh()->stock)->toEqual(16.0)
        ->and($this->red->fresh()->stock)->toEqual(240.0)
        ->and($this->brush->fresh()->stock)->toEqual(40.0);

    $this->get(route('figures.index'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('waiting.0.remaining', 5)
        ->where('categories.0.kits.0.combos', fn ($combos) => collect($combos)->firstWhere('id', $this->heliBusBike->id)['sold'] == 3));

    $this->get(route('paint-sets.index'))->assertInertia(fn (AssertableInertia $page) => $page->where('waiting.0.remaining', 6));
});

test('figures picked later on the sale take stock then, and never more than the kits left', function () {
    $this->post(route('sales.store'), kitSale(qty: 4))->assertSessionHasNoErrors();
    $item = Sale::query()->sole()->items()->sole();

    expect($this->car->fresh()->stock)->toEqual(10.0);

    $this->post(route('sale-assignments.store', $item), ['type' => 'figures', 'designs' => [$this->car->id => 2, $this->bike->id => 1], 'qty' => 3])->assertSessionHasNoErrors();
    $this->post(route('sale-assignments.store', $item), ['type' => 'figures', 'combo_id' => $this->carBikeBus->id, 'qty' => 2])->assertSessionHasErrors('assign');

    expect($this->car->fresh()->stock)->toEqual(4.0)
        ->and($this->bike->fresh()->stock)->toEqual(7.0)
        ->and($item->assignments()->sole()->label)->toBe('2 × Car + Bike');
});

test('a combination of the wrong size or a paint set with the wrong pots is refused', function () {
    $twoFigures = app(FigureService::class)->combo([$this->car->id => 1, $this->bike->id => 1]);
    $twoPot = app(PaintSetService::class)->create([$this->red->id, $this->blue->id], 3);

    $this->post(route('sales.store'), kitSale(['figures' => [['combo_id' => $twoFigures->id, 'qty' => 1]]]))->assertSessionHasErrors('items');
    $this->post(route('sales.store'), kitSale(['paint' => [['component_id' => $twoPot->id, 'qty' => 1]]]))->assertSessionHasErrors('items');
    $this->post(route('sales.store'), kitSale(['figures' => [['combo_id' => $this->carBikeBus->id, 'qty' => 11]]]))->assertSessionHasErrors('items.0.assign.figures');

    expect(Sale::query()->count())->toBe(0)
        ->and($this->car->fresh()->stock)->toEqual(10.0);
});

test('removing a row or deleting the sale puts the figures and paint sets back', function () {
    $this->post(route('stock.make', $this->set), ['qty' => 20, 'made_on' => '2026-11-26']);
    $this->post(route('sales.store'), kitSale([
        'figures' => [['combo_id' => $this->carBikeBus->id, 'qty' => 2]],
        'paint' => [['component_id' => $this->set->id, 'qty' => 2]],
    ]));
    $sale = Sale::query()->sole();
    $costWithRows = $sale->cost_total;

    $this->delete(route('sale-assignments.destroy', SaleItemAssignment::query()->where('type', 'paint')->sole()))->assertRedirect();

    expect($this->set->fresh()->stock)->toEqual(20.0)
        ->and($sale->fresh()->cost_total)->toEqual(round($costWithRows - 2 * 21, 2));

    $this->delete(route('sales.destroy', $sale))->assertRedirect();

    expect($this->car->fresh()->stock)->toEqual(10.0)
        ->and($this->brush->fresh()->stock)->toEqual(50.0);
});

test('a kit without figure or paint settings sells as before', function () {
    $plain = ProductOption::factory()->create();
    $plain->parts()->create(['material_id' => $this->brush->id, 'qty' => 1]);

    $this->post(route('sales.store'), [
        'sold_on' => '2026-11-26', 'sale_type' => 'retail', 'payment_method' => 'cash', 'delivery_method' => 'pickup',
        'items' => [['product_option_id' => $plain->id, 'qty' => 2, 'unit_price' => 500]],
    ])->assertSessionHasNoErrors();

    $this->get(route('figures.index'))->assertInertia(fn (AssertableInertia $page) => $page->where('waiting', []));
});

test('making figure sets moves single figures into set stock, and selling uses sets first', function () {
    $this->post(route('figure-combos.make', $this->carBikeBus), ['qty' => 4, 'made_on' => '2026-11-26'])->assertSessionHasNoErrors();

    $setPart = $this->carBikeBus->fresh()->setPart;

    expect($setPart->stock)->toEqual(4.0)
        ->and($this->car->fresh()->stock)->toEqual(6.0)
        ->and($this->bus->fresh()->stock)->toEqual(6.0);

    $this->post(route('sales.store'), kitSale(['figures' => [['combo_id' => $this->carBikeBus->id, 'qty' => 6]]]))->assertSessionHasNoErrors();

    expect($setPart->fresh()->stock)->toEqual(0.0)
        ->and($this->car->fresh()->stock)->toEqual(4.0)
        ->and($this->bike->fresh()->stock)->toEqual(4.0);
});

test('sets recorded when packing leave stock once, and selling those packed kits does not take them again', function () {
    $this->post(route('stock.make', $this->set), ['qty' => 20, 'made_on' => '2026-11-26']);

    $this->post(route('stock.pack', $this->kit), [
        'qty' => 5, 'packed_on' => '2026-11-26',
        'assign' => ['figures' => [['combo_id' => $this->carBikeBus->id, 'qty' => 3]], 'paint' => [['component_id' => $this->set->id, 'qty' => 5]]],
    ])->assertSessionHasNoErrors();

    expect($this->kit->fresh()->stock)->toEqual(5.0)
        ->and($this->car->fresh()->stock)->toEqual(7.0)
        ->and($this->set->fresh()->stock)->toEqual(15.0);

    $this->post(route('sales.store'), kitSale(['figures' => [['combo_id' => $this->carBikeBus->id, 'qty' => 2]], 'paint' => [['component_id' => $this->set->id, 'qty' => 2]]], qty: 2))->assertSessionHasNoErrors();

    $rows = Sale::query()->sole()->items()->sole()->assignments;

    expect($this->car->fresh()->stock)->toEqual(7.0)
        ->and($this->set->fresh()->stock)->toEqual(15.0)
        ->and($rows->every(fn ($row) => $row->pack_record_id !== null))->toBeTrue();

    $this->post(route('stock.unpack', $this->kit), ['qty' => 3, 'packed_on' => '2026-11-27'])->assertSessionHasNoErrors();

    expect($this->kit->fresh()->stock)->toEqual(0.0)
        ->and($this->car->fresh()->stock)->toEqual(8.0)
        ->and($this->set->fresh()->stock)->toEqual(18.0);
});

test('packing rows cannot add up to more kits than packed', function () {
    $this->post(route('stock.pack', $this->kit), [
        'qty' => 2, 'packed_on' => '2026-11-26',
        'assign' => ['figures' => [['combo_id' => $this->carBikeBus->id, 'qty' => 3]]],
    ])->assertSessionHasErrors('assign');

    expect($this->kit->fresh()->stock)->toEqual(0.0)
        ->and($this->car->fresh()->stock)->toEqual(10.0);
});

test('damaged figures are counted on the figures page', function () {
    $this->post(route('stock.adjust-component', $this->car), ['qty' => -2, 'reason' => 'damaged'])->assertSessionHasNoErrors();

    $this->get(route('figures.index'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('categories.0.kits.0.designs', fn ($designs) => collect($designs)->firstWhere('id', $this->car->id)['damaged'] == 2
            && collect($designs)->firstWhere('id', $this->car->id)['stock'] == 8));
});

test('making one figure set recounts the others that share its figures, and never makes more than the figures allow', function () {
    $this->post(route('figure-combos.make', $this->carBikeBus), ['qty' => 7, 'made_on' => '2026-11-27'])->assertSessionHasNoErrors();

    $this->get(route('figures.index'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('categories.0.kits.0.sizes', [3])
        ->where('categories.0.kits.0.combos', fn ($combos) => collect($combos)->firstWhere('id', $this->heliBusBike->id)['from_figures'] == 3
            && collect($combos)->firstWhere('id', $this->carBikeBus->id)['stock'] == 7));

    $this->post(route('figure-combos.make', $this->heliBusBike), ['qty' => 4, 'made_on' => '2026-11-27'])->assertSessionHasErrors('qty');

    expect($this->helicopter->fresh()->stock)->toEqual(10.0)
        ->and($this->bus->fresh()->stock)->toEqual(3.0);
});

test('packing a figure set and paint set pair recounts every pair on the product page', function () {
    $this->post(route('stock.make', $this->set), ['qty' => 5, 'made_on' => '2026-11-27']);

    $this->post(route('stock.pack', $this->kit), [
        'qty' => 2, 'packed_on' => '2026-11-27',
        'assign' => ['figures' => [['combo_id' => $this->carBikeBus->id, 'qty' => 2]], 'paint' => [['component_id' => $this->set->id, 'qty' => 2]]],
    ])->assertSessionHasNoErrors();

    $this->get(route('options.show', $this->kit))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('option.stock', 2)
        ->where('pickable.figure_combos', fn ($combos) => collect($combos)->firstWhere('id', $this->carBikeBus->id)['can_make'] == 8
            && collect($combos)->firstWhere('id', $this->heliBusBike->id)['can_make'] == 8)
        ->where('pickable.paint_sets.0.stock', 3));
});
