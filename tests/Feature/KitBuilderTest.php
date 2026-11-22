<?php

use App\Models\Component;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\User;
use App\Services\Costing;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->kit = Product::factory()->create();
    $this->figure = Component::factory()->create(['yield_qty' => 1, 'wastage_pct' => 0]);
    $this->paint = Material::factory()->create(['unit_cost' => 40]);
    $plaster = Material::factory()->create(['unit_cost' => 0.5]);
    $this->figure->stages()->create(['name' => 'Casting', 'sort_order' => 1])->lines()->create(['material_id' => $plaster->id, 'qty' => 200]);
});

test('kit builder pages render', function () {
    $this->get(route('options.create', $this->kit))->assertOk();

    $option = ProductOption::factory()->create(['product_id' => $this->kit->id]);
    $this->get(route('options.edit', $option))->assertOk();
});

test('creating an option saves its parts and the kit cost follows them', function () {
    $this->post(route('options.store', $this->kit), [
        'code' => 'KIT-1F2P',
        'name' => '1 figure 2 paints',
        'retail_price' => 300,
        'parts' => [
            ['kind' => 'component', 'id' => $this->figure->id, 'qty' => 1],
            ['kind' => 'material', 'id' => $this->paint->id, 'qty' => 2],
        ],
    ])->assertRedirect();

    $option = ProductOption::query()->where('code', 'KIT-1F2P')->firstOrFail();

    // figure 100 + 2 paints 80 = 180
    expect($option->parts)->toHaveCount(2)
        ->and(app(Costing::class)->optionCost($option))->toEqualWithDelta(180, 0.0001);
});

test('editing an option replaces its parts', function () {
    $option = ProductOption::factory()->create(['product_id' => $this->kit->id]);
    $option->parts()->create(['material_id' => $this->paint->id, 'qty' => 6]);

    $this->put(route('options.update', $option), [
        'code' => $option->code,
        'name' => $option->name,
        'retail_price' => 500,
        'parts' => [['kind' => 'component', 'id' => $this->figure->id, 'qty' => 2]],
    ])->assertRedirect(route('options.show', $option));

    expect($option->fresh()->parts)->toHaveCount(1)
        ->and($option->fresh()->parts->first()->component_id)->toBe($this->figure->id);
});

test('a part pointing at a missing item is refused', function () {
    $this->post(route('options.store', $this->kit), [
        'code' => 'KIT-BAD',
        'name' => 'Bad',
        'retail_price' => 100,
        'parts' => [['kind' => 'material', 'id' => 999999, 'qty' => 1]],
    ])->assertSessionHasErrors('parts.0.id');

    expect(ProductOption::query()->where('code', 'KIT-BAD')->exists())->toBeFalse();
});

test('components are stored apart from kits', function () {
    $this->post(route('components.store'), ['code' => 'CMP-NEW', 'name' => 'Painting set', 'yield_qty' => 10, 'wastage_pct' => 0])
        ->assertRedirect();

    expect(Component::query()->where('code', 'CMP-NEW')->exists())->toBeTrue()
        ->and(Product::query()->where('code', 'CMP-NEW')->exists())->toBeFalse();

    $this->get(route('products.index'))
        ->assertInertia(fn ($page) => $page->where('products', fn ($rows) => collect($rows)->doesntContain('code', 'CMP-NEW')));
});
