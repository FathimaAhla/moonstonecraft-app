<?php

namespace App\Http\Controllers;

use App\Models\Material;
use App\Models\PurchaseItem;
use App\Services\StockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class MaterialController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('materials/index', [
            'materials' => Material::query()->orderByDesc('is_purchasable')->orderBy('name')->get(),
        ]);
    }

    public function show(Material $material): Response
    {
        return Inertia::render('materials/show', [
            'material' => $material,
            'movements' => $material->movements()->latest('moved_on')->latest('id')->limit(200)->get(),
            'purchases' => PurchaseItem::query()->with('purchase:id,purchase_no,purchased_on')->where('material_id', $material->id)->latest('id')->limit(20)->get(),
        ]);
    }

    public function store(Request $request, StockService $stock): RedirectResponse
    {
        $data = $this->validated($request);
        $openingStock = (float) ($data['stock'] ?? 0);
        $openingCost = $data['opening_unit_cost'] ?? null;
        unset($data['stock'], $data['opening_unit_cost']);

        $material = DB::transaction(function () use ($data, $openingStock, $openingCost, $stock) {
            $material = Material::query()->create($data + ['purchase_unit_cost' => $openingCost ?? 0]);

            if ($openingStock > 0 && $material->tracks_stock) {
                $stock->move($material->id, $openingStock, 'adjustment');
            }

            return $material;
        });

        $this->toast('Material added.');
        Inertia::flash('created_material', ['id' => $material->id]);

        return back();
    }

    public function update(Request $request, Material $material): RedirectResponse
    {
        $data = $this->validated($request, false);
        unset($data['opening_unit_cost']);
        $material->update($data);
        $this->toast('Material updated.');

        return back();
    }

    /**
     * Switch the active cost between the purchase-derived cost and the manual cost.
     * Neither stored value is changed by switching.
     */
    public function cost(Request $request, Material $material): RedirectResponse
    {
        $data = $request->validate([
            'cost_source' => ['required', Rule::in(['purchase', 'manual'])],
            'manual_unit_cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        if ($data['cost_source'] === 'purchase' && ! $material->is_purchasable) {
            throw ValidationException::withMessages(['cost_source' => 'This item is not purchasable, so it has no purchase-derived cost.']);
        }

        $manual = $data['manual_unit_cost'] ?? $material->manual_unit_cost;

        if ($data['cost_source'] === 'manual' && $manual === null) {
            throw ValidationException::withMessages(['manual_unit_cost' => 'Enter a manual unit cost first.']);
        }

        $material->update(['cost_source' => $data['cost_source'], 'manual_unit_cost' => $manual]);
        $this->toast($data['cost_source'] === 'manual' ? 'Manual cost is now active.' : 'Latest purchase-derived cost is now active.');

        return back();
    }

    /**
     * Manual stock correction, written to the ledger as an adjustment.
     */
    public function adjust(Request $request, Material $material, StockService $stock): RedirectResponse
    {
        if (! $material->tracks_stock) {
            $this->toast('This item is not stock-tracked, so there is no stock to adjust.', 'error');

            return back();
        }

        $data = $request->validate(['qty' => ['required', 'numeric', 'not_in:0']]);
        DB::transaction(fn () => $stock->move($material->id, (float) $data['qty'], 'adjustment'));
        $this->toast('Stock adjusted.');

        return back();
    }

    public function destroy(Material $material): RedirectResponse
    {
        return $this->deleteOrRefuse(fn () => $material->delete(), 'Material');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $withStock = true): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'unit' => ['required', 'string', 'max:20'],
            'is_purchasable' => ['required', 'boolean'],
            'tracks_stock' => ['required', 'boolean'],
            'purchase_unit' => ['nullable', 'string', 'max:20'],
            'purchase_conversion' => ['nullable', 'numeric', 'gt:0'],
            'cost_source' => ['required', Rule::in(['purchase', 'manual'])],
            'manual_unit_cost' => ['nullable', 'numeric', 'min:0'],
            'opening_unit_cost' => ['nullable', 'numeric', 'min:0'],
            'reorder_level' => ['nullable', 'numeric', 'min:0'],
            ...($withStock ? ['stock' => ['nullable', 'numeric', 'min:0']] : []),
        ]);

        $errors = [];

        if ($data['tracks_stock'] && ! $data['is_purchasable']) {
            $errors['tracks_stock'] = 'Only purchasable items can be stock-tracked: stock can only come in through purchases.';
        }

        if (! $data['is_purchasable'] && $data['cost_source'] === 'purchase') {
            $errors['cost_source'] = 'A non-purchasable item has no purchases, so it must use a manual cost.';
        }

        if ($data['cost_source'] === 'manual' && ! isset($data['manual_unit_cost'])) {
            $errors['manual_unit_cost'] = 'Enter a manual unit cost.';
        }

        if (! $data['is_purchasable'] && filled($data['purchase_unit'] ?? null)) {
            $errors['purchase_unit'] = 'A purchase unit only applies to purchasable items.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $hasPurchaseUnit = $data['is_purchasable'] && filled($data['purchase_unit'] ?? null) && $data['purchase_unit'] !== $data['unit'];
        $data['purchase_unit'] = $hasPurchaseUnit ? $data['purchase_unit'] : null;
        $data['purchase_conversion'] = $hasPurchaseUnit ? ($data['purchase_conversion'] ?? 1) : 1;
        $data['reorder_level'] = $data['tracks_stock'] ? ($data['reorder_level'] ?? 0) : 0;

        if (! $data['tracks_stock']) {
            unset($data['stock']);
        }

        return $data;
    }
}
