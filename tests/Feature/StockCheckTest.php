<?php

use App\Models\Component;
use App\Models\Material;
use App\Models\StockCheck;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->car = Component::factory()->keepsStock(36)->create(['code' => 'VEH-CAR']);
    $this->bus = Component::factory()->keepsStock(6)->create(['code' => 'VEH-BUS']);
    $this->notStocked = Component::factory()->create();
    $this->paint = Material::factory()->create(['stock' => 150, 'unit_cost' => 4]);
});

function countLines(StockCheck $check, array $counted): array
{
    return $check->lines->mapWithKeys(fn ($line) => [$line->id => ['counted_qty' => $counted[$line->item_id] ?? $line->system_qty]])->all();
}

test('starting a check freezes system quantities for the chosen scope', function () {
    $this->actingAs($this->user)->post(route('stock-checks.store', ['scope' => 'components', 'checked_on' => '2026-11-30']))->assertRedirect();

    $check = StockCheck::query()->with('lines')->sole();

    expect($check->status)->toBe('draft')
        ->and($check->lines->pluck('system_qty', 'item_id')->all())->toEqual([$this->car->id => 36.0, $this->bus->id => 6.0]);
});

test('completing a check writes missing and found to the ledger and locks it', function () {
    $this->actingAs($this->user)->post(route('stock-checks.store', ['scope' => 'components', 'checked_on' => '2026-11-30']));
    $check = StockCheck::query()->with('lines')->sole();

    $this->actingAs($this->user)->put(route('stock-checks.update', $check), ['lines' => countLines($check, [$this->car->id => 33, $this->bus->id => 7])])->assertRedirect();
    $this->actingAs($this->user)->post(route('stock-checks.complete', $check))->assertRedirect();

    expect($this->car->fresh()->stock)->toEqual(33.0)
        ->and($this->bus->fresh()->stock)->toEqual(7.0)
        ->and(StockMovement::query()->where('component_id', $this->car->id)->sole()->reason)->toBe('missing')
        ->and(StockMovement::query()->where('component_id', $this->bus->id)->sole()->reason)->toBe('found')
        ->and($check->fresh()->status)->toBe('completed');

    $this->actingAs($this->user)->put(route('stock-checks.update', $check), ['lines' => countLines($check->fresh('lines'), [$this->car->id => 0])]);
    $this->actingAs($this->user)->delete(route('stock-checks.destroy', $check));

    expect($check->fresh())->not->toBeNull()
        ->and($check->lines()->where('item_id', $this->car->id)->value('counted_qty'))->toEqual(33.0);
});

test('stock that moves while counting is not reported as missing', function () {
    $this->actingAs($this->user)->post(route('stock-checks.store', ['scope' => 'components', 'checked_on' => '2026-11-30']));
    $check = StockCheck::query()->with('lines')->sole();

    app(StockService::class)->moveComponent($this->car->id, -2, 'sale');

    $this->actingAs($this->user)->put(route('stock-checks.update', $check), ['lines' => countLines($check, [$this->car->id => 35])]);
    $this->actingAs($this->user)->post(route('stock-checks.complete', $check));

    expect($this->car->fresh()->stock)->toEqual(33.0);
});

test('a counted difference can be given its own reason and uncounted lines are skipped', function () {
    $this->actingAs($this->user)->post(route('stock-checks.store', ['scope' => 'all', 'checked_on' => '2026-11-30']));
    $check = StockCheck::query()->with('lines')->sole();
    $paintLine = $check->lines->firstWhere('item_type', 'material');

    $this->actingAs($this->user)->put(route('stock-checks.update', $check), ['lines' => [
        $paintLine->id => ['counted_qty' => 110, 'reason' => 'damaged', 'note' => 'dried'],
    ]]);
    $this->actingAs($this->user)->post(route('stock-checks.complete', $check));

    expect($this->paint->fresh()->stock)->toEqual(110.0)
        ->and(StockMovement::query()->where('material_id', $this->paint->id)->sole()->only('qty', 'reason', 'note'))->toBe(['qty' => -40.0, 'reason' => 'damaged', 'note' => 'dried'])
        ->and($this->car->fresh()->stock)->toEqual(36.0);
});
