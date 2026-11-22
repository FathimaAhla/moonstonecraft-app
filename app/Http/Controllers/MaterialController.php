<?php

namespace App\Http\Controllers;

use App\Models\Material;
use App\Services\StockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MaterialController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('materials/index', [
            'materials' => Material::query()->orderBy('type')->orderBy('name')->get(),
        ]);
    }

    public function show(Material $material): Response
    {
        return Inertia::render('materials/show', [
            'material' => $material,
            'movements' => $material->movements()->latest('moved_on')->latest('id')->limit(200)->get(),
        ]);
    }

    public function store(Request $request, StockService $stock): RedirectResponse
    {
        $data = $this->validated($request);
        $openingStock = (float) ($data['stock'] ?? 0);
        unset($data['stock']);

        DB::transaction(function () use ($data, $openingStock, $stock) {
            $material = Material::query()->create($data);

            if ($openingStock > 0 && $material->type === 'material') {
                $stock->move($material->id, $openingStock, 'adjustment');
            }
        });
        $this->toast('Material added.');

        return back();
    }

    public function update(Request $request, Material $material): RedirectResponse
    {
        $material->update($this->validated($request, false));
        $this->toast('Material updated.');

        return back();
    }

    /**
     * Manual stock correction, written to the ledger as an adjustment.
     */
    public function adjust(Request $request, Material $material, StockService $stock): RedirectResponse
    {
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
            'type' => ['required', Rule::in(['material', 'overhead'])],
            'unit_cost' => ['required', 'numeric', 'min:0'],
            'reorder_level' => ['nullable', 'numeric', 'min:0'],
            ...($withStock ? ['stock' => ['nullable', 'numeric', 'min:0']] : []),
        ]);
        $data['reorder_level'] ??= 0;

        return $data;
    }
}
