<?php

use App\Models\Material;
use App\Models\MaterialContainer;
use App\Models\ProductOption;
use App\Models\Purchase;
use App\Models\User;
use App\Services\ContainerService;
use App\Services\Costing;
use App\Services\KitGroups;
use App\Services\KitStockService;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;

beforeEach(fn () => $this->actingAs(User::factory()->create()));

test('buying a pack size converts to recipe units and shows its price per unit', function () {
    $paint = Material::factory()->create(['unit' => 'ml', 'stock' => 0, 'unit_cost' => 0]);
    $small = $paint->packSizes()->create(['name' => '75 ml', 'size' => 75]);
    $large = $paint->packSizes()->create(['name' => '1 L', 'size' => 1000]);

    $this->post(route('purchases.store'), [
        'purchased_on' => '2026-11-20',
        'lines' => [
            // the typed size is ignored: the chosen pack decides
            ['material_id' => $paint->id, 'material_pack_size_id' => $small->id, 'conversion_factor' => 5, 'qty' => 2, 'line_cost' => 900],
            ['material_id' => $paint->id, 'material_pack_size_id' => $large->id, 'qty' => 1, 'line_cost' => 3800],
        ],
    ])->assertSessionHasNoErrors();

    expect($paint->fresh()->stock)->toEqual(1150.0)
        ->and(Purchase::query()->sole()->items->pluck('purchase_unit')->all())->toBe(['75 ml', '1 L']);

    $this->get(route('materials.show', $paint))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('packSizes.0.last_price', 450)
        ->where('packSizes.0.last_unit_cost', 6)
        ->where('packSizes.1.last_unit_cost', 3.8));
});

test('a pack size of another material is refused', function () {
    $paint = Material::factory()->create();
    $glue = Material::factory()->create();
    $glueSize = $glue->packSizes()->create(['name' => 'Tube', 'size' => 50]);

    $this->post(route('purchases.store'), [
        'purchased_on' => '2026-11-20',
        'lines' => [['material_id' => $paint->id, 'material_pack_size_id' => $glueSize->id, 'qty' => 1, 'line_cost' => 100]],
    ])->assertSessionHasErrors('lines.0.material_pack_size_id');

    expect(Purchase::query()->count())->toBe(0);
});

test('packing kits does not deduct a container tracked material but still costs it', function () {
    $glue = Material::factory()->create(['stock' => 500, 'unit_cost' => 2, 'track_by_container' => true]);
    $option = ProductOption::factory()->create();
    $option->parts()->create(['material_id' => $glue->id, 'qty' => 10]);

    app(KitStockService::class)->pack($option, 3);

    expect($glue->fresh()->stock)->toEqual(500.0)
        ->and(app(Costing::class)->optionCost($option->fresh()))->toEqual(20.0);
});

test('opening takes stock and finishing works out the real usage per kit', function () {
    $glue = Material::factory()->create(['stock' => 1000, 'unit_cost' => 2, 'track_by_container' => true]);
    $option = ProductOption::factory()->create();
    $option->parts()->create(['material_id' => $glue->id, 'qty' => 10]);

    $this->post(route('containers.store', $glue), ['label' => 'Bottle', 'size' => 500, 'opened_on' => '2026-11-01'])
        ->assertSessionHasNoErrors();
    $container = MaterialContainer::query()->sole();
    app(KitStockService::class)->pack($option, 40, Carbon::parse('2026-11-05'));

    expect($glue->fresh()->stock)->toEqual(500.0)
        ->and($container->cost)->toEqual(1000.0)
        ->and(app(ContainerService::class)->kitsSinceOpened($container))->toEqual(40.0);

    $this->post(route('containers.finish', $container), ['finished_on' => '2026-11-20', 'kits_made' => 50])
        ->assertSessionHasNoErrors();

    expect(app(ContainerService::class)->measured($glue))->toBe(['qty_per_kit' => 10.0, 'cost_per_kit' => 20.0, 'containers' => 1]);
});

test('more than the unopened quantity cannot be opened', function () {
    $glue = Material::factory()->create(['stock' => 100, 'track_by_container' => true]);

    $this->post(route('containers.store', $glue), ['label' => 'Bottle', 'size' => 500, 'opened_on' => '2026-11-01'])
        ->assertSessionHasErrors('size');

    expect($glue->fresh()->stock)->toEqual(100.0)
        ->and(MaterialContainer::query()->count())->toBe(0);
});

test('removing an opened container returns its quantity, but a finished one is kept', function () {
    $glue = Material::factory()->create(['stock' => 1000, 'track_by_container' => true]);
    $service = app(ContainerService::class);
    $mistake = $service->open($glue, 200, 'Bottle', Carbon::parse('2026-11-01'));
    $used = $service->open($glue, 300, 'Bottle', Carbon::parse('2026-11-01'));
    $service->finish($used, Carbon::parse('2026-11-10'), 5);

    $this->delete(route('containers.destroy', $mistake))->assertSessionHasNoErrors();
    $this->delete(route('containers.destroy', $used))->assertSessionHasErrors('container');

    expect($glue->fresh()->stock)->toEqual(700.0)
        ->and(MaterialContainer::query()->count())->toBe(1);
});

test('kit builder sections are guessed from the name unless chosen', function (string $name, ?string $chosen, string $expected) {
    expect(KitGroups::resolve($chosen, $name))->toBe($expected);
})->with([
    'brush wins over paint' => ['Paint brush', null, 'brush'],
    'paint' => ['Acrylic paint', null, 'paint'],
    'figure' => ['Animal figure', null, 'figure'],
    'unknown' => ['Water', null, 'other'],
    'chosen wins' => ['Water', 'paint', 'paint'],
]);

test('a kit can be copied only within its own product', function () {
    $source = ProductOption::factory()->create();
    $source->parts()->create(['material_id' => Material::factory()->create()->id, 'qty' => 2]);
    $other = ProductOption::factory()->create();

    $this->get(route('options.create', ['product' => $source->product_id, 'copy' => $source->id]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('copyFrom.parts.0.qty', 2));
    $this->get(route('options.create', ['product' => $source->product_id, 'copy' => $other->id]))->assertNotFound();
});
