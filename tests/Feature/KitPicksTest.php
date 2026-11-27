<?php

use App\Models\Category;
use App\Models\Component;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\Sale;
use App\Models\User;
use App\Services\FigureService;
use App\Services\KitCounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->actingAs(User::factory()->create());

    $this->painting = Category::factory()->create(['name' => 'DIY painting kit', 'kit_picks' => KitCounts::PICKS_FIGURES_PAINT]);
    $this->astronautKit = Product::factory()->for($this->painting)->create(['name' => 'Astronaut Kit']);

    $this->figurePart = Component::factory()->create(['name' => 'Astronaut figure', 'keeps_stock' => true, 'stock' => 20]);
    $this->potPart = Component::factory()->create(['name' => 'Paint pot (2 ml filled)', 'keeps_stock' => true, 'stock' => 20]);
    $this->brush = Material::factory()->create(['name' => 'Paint brush', 'unit' => 'pc', 'stock' => 50, 'is_purchasable' => true, 'tracks_stock' => true]);

    $this->option = ProductOption::factory()->for($this->astronautKit)->create(['code' => 'AST-2F-3P-1B']);
});

function addRecipe(ProductOption $option): void
{
    $option->parts()->createMany([
        ['component_id' => test()->figurePart->id, 'qty' => 2],
        ['component_id' => test()->potPart->id, 'qty' => 3],
        ['material_id' => test()->brush->id, 'qty' => 1],
    ]);
    app(KitCounts::class)->sync($option);
}

function sellKits(ProductOption $option, float $qty, array $assign = []): TestResponse
{
    return test()->post(route('sales.store'), [
        'sold_on' => '2026-11-27', 'sale_type' => 'retail', 'payment_method' => 'cash', 'delivery_method' => 'pickup',
        'items' => [['product_option_id' => $option->id, 'qty' => $qty, 'unit_price' => 600, 'assign' => $assign]],
    ]);
}

test('a painting kit counts its figures, pots and brushes from the recipe as parts are added and removed', function () {
    $this->post(route('option-parts.store', $this->option), ['component_id' => $this->figurePart->id, 'qty' => 2])->assertSessionHasNoErrors();
    $this->post(route('option-parts.store', $this->option), ['component_id' => $this->potPart->id, 'qty' => 3])->assertSessionHasNoErrors();
    $this->post(route('option-parts.store', $this->option), ['material_id' => $this->brush->id, 'qty' => 1])->assertSessionHasNoErrors();

    expect($this->option->fresh()->only('figures_per_kit', 'paint_pots_per_kit'))->toBe(['figures_per_kit' => 2, 'paint_pots_per_kit' => 3]);

    $this->get(route('options.show', $this->option))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('option.brushes_per_kit', 1)
        ->where('option.picks', true)
        ->where('option.kit_name', 'Astronaut Kit'));

    $this->delete(route('option-parts.destroy', $this->option->parts()->where('component_id', $this->potPart->id)->sole()))->assertRedirect();

    expect($this->option->fresh()->paint_pots_per_kit)->toBe(0);
});

test('a cut paint set in the recipe counts as its number of pots', function () {
    $threePotSet = Component::factory()->create(['name' => '3-pot Red / Blue / Yellow', 'kit_group' => 'paint', 'pot_colours' => [1, 2, 3]]);
    $this->post(route('option-parts.store', $this->option), ['component_id' => $threePotSet->id, 'qty' => 2])->assertSessionHasNoErrors();

    expect($this->option->fresh()->paint_pots_per_kit)->toBe(6);
});

test('switching a category to pick nothing clears its kits counts, and switching back recounts them', function () {
    addRecipe($this->option);
    $update = fn (string $picks) => $this->put(route('categories.update', $this->painting), ['name' => 'DIY painting kit', 'code_prefix' => 'PNT', 'kit_picks' => $picks]);

    $update(KitCounts::PICKS_NONE)->assertSessionHasNoErrors();
    expect($this->option->fresh()->only('figures_per_kit', 'paint_pots_per_kit'))->toBe(['figures_per_kit' => 0, 'paint_pots_per_kit' => 0]);

    $update(KitCounts::PICKS_FIGURES_PAINT)->assertSessionHasNoErrors();
    expect($this->option->fresh()->only('figures_per_kit', 'paint_pots_per_kit'))->toBe(['figures_per_kit' => 2, 'paint_pots_per_kit' => 3]);
});

test('a category must pick one of the known choices', function () {
    $this->put(route('categories.update', $this->painting), ['name' => 'DIY painting kit', 'code_prefix' => 'PNT', 'kit_picks' => 'colour'])
        ->assertSessionHasErrors('kit_picks');

    expect($this->painting->fresh()->kit_picks)->toBe(KitCounts::PICKS_FIGURES_PAINT);
});

test('selling a painting kit takes the picked figure set, never the recipe figure and pot lines', function () {
    addRecipe($this->option);
    $figures = app(FigureService::class);
    $astronautOne = $figures->createDesign($this->astronautKit, 'Astronaut 1');
    $astronautTwo = $figures->createDesign($this->astronautKit, 'Astronaut 2');
    Component::query()->whereIn('id', [$astronautOne->id, $astronautTwo->id])->update(['stock' => 10]);
    $pair = $figures->combo([$astronautOne->id => 1, $astronautTwo->id => 1]);

    sellKits($this->option, 2, ['figures' => [['combo_id' => $pair->id, 'qty' => 2]]])->assertSessionHasNoErrors();

    expect($astronautOne->fresh()->stock)->toEqual(8.0)
        ->and($this->figurePart->fresh()->stock)->toEqual(20.0)
        ->and($this->potPart->fresh()->stock)->toEqual(20.0)
        ->and($this->brush->fresh()->stock)->toEqual(48.0);
});

test('a kit in a category that picks nothing still takes every recipe line', function () {
    $pipeCleaner = Product::factory()->for(Category::factory()->create(['kit_picks' => KitCounts::PICKS_NONE]))->create();
    $plain = ProductOption::factory()->for($pipeCleaner)->create();
    addRecipe($plain);

    sellKits($plain, 2)->assertSessionHasNoErrors();

    expect($plain->fresh()->figures_per_kit)->toBe(0)
        ->and($this->figurePart->fresh()->stock)->toEqual(16.0)
        ->and($this->potPart->fresh()->stock)->toEqual(14.0);
});

test('a figure set uses figures of one kit, and a kit refuses another kits figure set', function () {
    addRecipe($this->option);
    $vehicleKit = Product::factory()->for($this->painting)->create(['name' => 'Vehicle Kit']);
    $figures = app(FigureService::class);
    $astronaut = $figures->createDesign($this->astronautKit, 'Astronaut 1');
    $car = $figures->createDesign($vehicleKit, 'Car');
    $bike = $figures->createDesign($vehicleKit, 'Bike');
    Component::query()->whereIn('id', [$astronaut->id, $car->id, $bike->id])->update(['stock' => 10]);

    $this->post(route('figure-combos.store'), ['designs' => [$astronaut->id => 1, $car->id => 1]])->assertSessionHasErrors('designs');

    $carBike = $figures->combo([$car->id => 1, $bike->id => 1]);
    sellKits($this->option, 1, ['figures' => [['combo_id' => $carBike->id, 'qty' => 1]]])->assertSessionHasErrors('items');

    expect($car->fresh()->stock)->toEqual(10.0);
});

test('the figures page groups figures by category and kit, and figures are added under a kit', function () {
    $legacy = Component::factory()->create(['name' => 'Old rocket', 'kit_group' => 'figure', 'figure_family' => 'Space', 'keeps_stock' => true]);

    $this->post(route('figures.store'), ['product_id' => $this->astronautKit->id, 'name' => 'Astronaut 1'])->assertSessionHasNoErrors();

    $this->get(route('figures.index'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('categories.0.name', 'DIY painting kit')
        ->where('categories.0.kits.0.name', 'Astronaut Kit')
        ->where('categories.0.kits.0.designs.0.name', 'Astronaut 1')
        ->where('unlinked.0.id', $legacy->id));

    $this->patch(route('figures.kit', $legacy), ['product_id' => $this->astronautKit->id])->assertSessionHasNoErrors();

    expect($legacy->fresh()->product_id)->toBe($this->astronautKit->id);
});

test('the products list filters by kit, figure and pot counts, low stock and status', function () {
    addRecipe($this->option);
    $single = ProductOption::factory()->for($this->astronautKit)->create(['code' => 'AST-1F-1P-1B', 'stock' => 10, 'reorder_level' => 2]);
    $single->parts()->createMany([['component_id' => $this->figurePart->id, 'qty' => 1], ['component_id' => $this->potPart->id, 'qty' => 1], ['material_id' => $this->brush->id, 'qty' => 1]]);
    $retired = ProductOption::factory()->for($this->astronautKit)->create(['code' => 'AST-OLD', 'active' => false]);
    $otherKit = ProductOption::factory()->create(['code' => 'VEH-1F']);

    $codes = fn (array $query) => collect($this->get(route('options.index', $query))->assertOk()->viewData('page')['props']['products'])->pluck('code')->all();

    expect($codes(['kit' => $this->astronautKit->id]))->toBe(['AST-1F-1P-1B', 'AST-2F-3P-1B'])
        ->and($codes(['kit' => $this->astronautKit->id, 'low' => 1]))->toBe(['AST-2F-3P-1B'])
        ->and($codes(['status' => 'inactive']))->toBe(['AST-OLD'])
        ->and($codes(['category' => $this->painting->id, 'status' => 'all']))->toBe(['AST-1F-1P-1B', 'AST-2F-3P-1B', 'AST-OLD'])
        ->and($codes([]))->toContain($otherKit->code);
});

test('the sale form asks for figures and paint only on painting kits, and a sale without picks waits for them', function () {
    addRecipe($this->option);
    $pipeCleaner = ProductOption::factory()->for(Product::factory()->for(Category::factory()->create(['kit_picks' => KitCounts::PICKS_NONE])))->create();
    addRecipe($pipeCleaner);

    $this->get(route('sales.create'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('options', fn ($options) => collect($options)->firstWhere('id', $this->option->id)['picks'] === true
            && collect($options)->firstWhere('id', $pipeCleaner->id)['picks'] === false
            && collect($options)->firstWhere('id', $pipeCleaner->id)['figures_per_kit'] === 0));

    sellKits($this->option, 3)->assertSessionHasNoErrors();
    $sale = Sale::query()->sole();

    $this->get(route('sales.show', $sale))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('assignments.'.$sale->items()->sole()->id.'.figures.remaining', 3)
        ->where('assignments.'.$sale->items()->sole()->id.'.paint.remaining', 3));

    $this->get(route('figures.index'))->assertInertia(fn (AssertableInertia $page) => $page->where('waiting.0.remaining', 3));
});

test('price check lists active products with their counts, prices and stock', function () {
    addRecipe($this->option);
    $this->option->update(['retail_price' => 625.73, 'wholesale_price' => 500, 'stock' => 4]);
    ProductOption::factory()->for($this->astronautKit)->create(['code' => 'AST-OLD', 'active' => false]);

    $this->get(route('price-check'))->assertInertia(fn (AssertableInertia $page) => $page
        ->component('options/price-check')
        ->has('products', 1)
        ->where('products.0.code', 'AST-2F-3P-1B')
        ->where('products.0.figures', 2)
        ->where('products.0.pots', 3)
        ->where('products.0.brushes', 1)
        ->where('products.0.retail_price', 625.73)
        ->where('products.0.wholesale_price', 500)
        ->where('products.0.stock', 4)
        ->where('kits.0.id', $this->astronautKit->id));
});
