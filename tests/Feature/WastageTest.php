<?php

use App\Models\Component;
use App\Models\Material;
use App\Models\User;
use App\Models\WastageLog;
use App\Services\Costing;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('product wastage reduces materials by exactly the usage', function () {
    $plaster = Material::factory()->create(['stock' => 1000, 'unit_cost' => 0.5]);
    $water = Material::factory()->create(['stock' => 1000, 'unit_cost' => 0.01]);
    $figure = Component::factory()->create(['yield_qty' => 10, 'wastage_pct' => 5]);
    $stage = $figure->stages()->create(['name' => 'Mixing']);
    $stage->lines()->create(['material_id' => $plaster->id, 'qty' => 200]);
    $stage->lines()->create(['material_id' => $water->id, 'qty' => 100]);
    $usage = app(Costing::class)->componentUsage($figure, 3);

    $this->actingAs(User::factory()->create())->post(route('wastage.store'), [
        'scope' => 'product', 'logged_on' => '2026-11-20', 'component_id' => $figure->id, 'qty' => 3,
    ])->assertRedirect();

    expect($plaster->fresh()->stock)->toEqualWithDelta(1000 - $usage[$plaster->id], 0.001)
        ->and($water->fresh()->stock)->toEqualWithDelta(1000 - $usage[$water->id], 0.001)
        ->and(WastageLog::query()->sole()->cost)->toEqualWithDelta(round(app(Costing::class)->componentUnitCost($figure) * 3, 2), 0.001);
});

test('material wastage reduces stock at current cost', function () {
    $plaster = Material::factory()->create(['stock' => 1000, 'unit_cost' => 0.5]);

    $this->actingAs(User::factory()->create())->post(route('wastage.store'), [
        'scope' => 'material', 'logged_on' => '2026-11-20', 'material_id' => $plaster->id, 'qty' => 100,
    ]);

    expect($plaster->fresh()->stock)->toEqual(900.0)
        ->and(WastageLog::query()->sole()->cost)->toEqual(50.0);
});

test('business wastage changes no stock', function () {
    $plaster = Material::factory()->create(['stock' => 1000]);

    $this->actingAs(User::factory()->create())->post(route('wastage.store'), [
        'scope' => 'business', 'logged_on' => '2026-11-20', 'amount' => 2500, 'reason' => 'Damaged parcel',
    ]);

    expect($plaster->fresh()->stock)->toEqual(1000.0)
        ->and(WastageLog::query()->sole()->cost)->toEqual(2500.0);
});
