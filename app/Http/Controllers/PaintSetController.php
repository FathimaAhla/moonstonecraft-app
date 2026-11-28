<?php

namespace App\Http\Controllers;

use App\Models\Component;
use App\Models\Material;
use App\Models\StockMovement;
use App\Services\AssignmentService;
use App\Services\KitStockService;
use App\Services\PaintSetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Paint colours, the 1/2/3/4/6 pot sets cut from filled strips, which kits use them,
 * kits sold still waiting for a paint set, and what each set sold.
 */
class PaintSetController extends Controller
{
    public function index(PaintSetService $paintSets, KitStockService $kits, AssignmentService $assignments): Response
    {
        $monthStart = now()->startOfMonth()->toDateString();
        $colours = Material::query()->orderBy('name')->get()->filter(fn (Material $material) => PaintSetService::isPaint($material))->values();
        $usedThisMonth = StockMovement::query()->whereIn('material_id', $colours->pluck('id'))->where('qty', '<', 0)
            ->whereDate('moved_on', '>=', $monthStart)->whereIn('reason', ['made', 'packing', 'sale'])
            ->groupBy('material_id')->selectRaw('material_id, -SUM(qty) as used')->pluck('used', 'material_id');

        $sets = $paintSets->all();
        $sold = $assignments->soldBy('paint');
        $pots = $paintSets->potsByColour();

        return Inertia::render('paint-sets/index', [
            'sets' => $sets->map(fn (Component $set) => $paintSets->summary($set) + [
                'from_paint' => $kits->canMakeComponent($set),
                'sold_month' => $sold[$set->id]['month'] ?? 0,
                'sold' => $sold[$set->id]['all'] ?? 0,
            ])->values(),
            'colours' => $colours->map(fn (Material $material) => [
                ...$material->only('id', 'name', 'colour', 'stock', 'unit', 'reorder_level'),
                'short_name' => PaintSetService::colourName($material),
                'used_this_month' => round((float) ($usedThisMonth[$material->id] ?? 0), 3),
                'pots' => $pots[$material->id] ?? 0,
            ]),
            'potMaterials' => Material::query()->where('tracks_stock', true)->orderBy('name')->get(['id', 'name', 'unit', 'stock'])
                ->reject(fn (Material $material) => $colours->contains('id', $material->id))->values(),
            'waiting' => $assignments->waiting('paint'),
            'potCounts' => PaintSetService::POT_COUNTS,
        ]);
    }

    public function store(Request $request, PaintSetService $paintSets): RedirectResponse
    {
        $data = $request->validate([
            'colours' => ['required', 'array', 'min:1', 'max:6'],
            'colours.*' => ['required', 'integer', 'exists:materials,id'],
            'ml_per_pot' => ['required', 'numeric', 'gt:0'],
            'pot_material_id' => ['nullable', 'integer', 'exists:materials,id'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        if (! in_array(count($data['colours']), PaintSetService::POT_COUNTS, true)) {
            return back()->withErrors(['colours' => 'A paint set has 1, 2, 3, 4 or 6 pots.']);
        }

        $set = $paintSets->create(array_map('intval', $data['colours']), (float) $data['ml_per_pot'], $data['pot_material_id'] ?? null, $data['name'] ?? null);
        $this->toast("Paint set {$set->name} added. Use \"Made sets\" to add stock.");

        return back();
    }

    public function storeColour(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:materials,name'],
            'colour' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'unit' => ['required', 'string', 'max:20'],
        ]);

        Material::query()->create($data + ['kit_group' => 'paint']);
        $this->toast("Colour {$data['name']} added. Buy it through Purchases to add paint stock.");

        return back();
    }

    public function updateColour(Request $request, Material $material): RedirectResponse
    {
        $material->update($request->validate(['colour' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/']]));

        return back();
    }
}
