<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Component;
use App\Models\ComponentStage;
use App\Models\Material;
use App\Models\StageLine;
use App\Services\Costing;
use App\Services\KitGroups;
use App\Services\KitStockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cost estimates for the parts you make (figure, painting set, packing), stage by stage.
 */
class ComponentController extends Controller
{
    public function index(Costing $costing): Response
    {
        $components = Component::query()
            ->with('category', 'stages.lines.material')
            ->withCount('stages')
            ->orderBy('code')
            ->get()
            ->map(fn (Component $component) => [
                ...$component->only('id', 'code', 'name', 'active', 'stages_count', 'yield_qty', 'wastage_pct', 'keeps_stock', 'stock'),
                'category' => $component->category?->name,
                'unit_cost' => round($costing->componentUnitCost($component), 2),
            ]);

        return Inertia::render('components/index', [
            'components' => $components,
            'categories' => Category::query()->orderBy('sort')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $component = Component::query()->create($this->validated($request));
        $this->toast('Component created. Add its making stages.');

        return to_route('components.show', $component);
    }

    public function show(Component $component, Costing $costing, KitStockService $kits): Response
    {
        $component->load('category', 'stages.lines.material');

        return Inertia::render('components/show', [
            'component' => $component->only('id', 'code', 'name', 'category_id', 'yield_qty', 'wastage_pct', 'keeps_stock', 'stock', 'prededucted_stock', 'reorder_level', 'notes', 'active', 'kit_group') + ['category' => $component->category?->name],
            'fromMaterials' => $kits->canMakeComponent($component),
            'movements' => $component->movements()->latest('moved_on')->latest('id')->limit(100)->get(),
            'adjustReasons' => KitStockService::ADJUST_REASONS,
            'stages' => $component->stages->map(fn (ComponentStage $stage) => [
                'id' => $stage->id,
                'name' => $stage->name,
                'sort_order' => $stage->sort_order,
                'lines' => $stage->lines->map(fn (StageLine $line) => [
                    'id' => $line->id,
                    'material_id' => $line->material_id,
                    'material' => $line->material?->name,
                    'unit' => $line->material?->unit,
                    'qty' => $line->qty,
                    'wastage_pct' => $line->wastage_pct,
                    'unit_cost' => $line->material?->unit_cost,
                    'cost_source' => $line->material?->cost_source,
                    'tracks_stock' => (bool) $line->material?->deductsStock(),
                    'cost' => round($costing->lineQty($line) * ($line->material?->unit_cost ?? 0), 4),
                ]),
            ]),
            'breakdown' => $costing->componentBreakdown($component),
            'materials' => Material::query()->orderBy('name')->get(['id', 'name', 'unit', 'unit_cost', 'cost_source', 'is_purchasable', 'tracks_stock', 'track_by_container']),
            'categories' => Category::query()->orderBy('sort')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, Component $component): RedirectResponse
    {
        $component->update($this->validated($request, $component));
        $this->toast('Component updated.');

        return back();
    }

    public function destroy(Component $component): RedirectResponse
    {
        $response = $this->deleteOrRefuse(fn () => $component->delete(), 'Component');

        return $component->exists ? $response : to_route('components.index');
    }

    public function storeStage(Request $request, Component $component): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $component->stages()->create($data + ['sort_order' => (int) $component->stages()->max('sort_order') + 1]);

        return back();
    }

    public function updateStage(Request $request, ComponentStage $stage): RedirectResponse
    {
        $stage->update($request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sort_order' => ['required', 'integer'],
        ]));

        return back();
    }

    public function destroyStage(ComponentStage $stage): RedirectResponse
    {
        $stage->delete();

        return back();
    }

    public function storeLine(Request $request, ComponentStage $stage): RedirectResponse
    {
        $stage->lines()->create($this->lineRules($request));

        return back();
    }

    public function updateLine(Request $request, StageLine $line): RedirectResponse
    {
        $line->update($this->lineRules($request));
        $this->toast('Recipe line updated.');

        return back();
    }

    /**
     * Copy a component's settings, stages and recipe lines into independent new records.
     * Stock, stock movements and history are never copied, and no materials are deducted.
     */
    public function copy(Request $request, Component $component): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', Rule::unique('components', 'code')],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        $copy = DB::transaction(function () use ($component, $data) {
            $component->load('stages.lines');
            $copy = $component->replicate(['stock', 'prededucted_stock']);
            $copy->fill(['code' => $data['code'], 'name' => $data['name'] ?: $component->name.' (copy)', 'stock' => 0, 'prededucted_stock' => 0]);
            $copy->save();

            foreach ($component->stages as $stage) {
                $newStage = $copy->stages()->create($stage->only('name', 'sort_order'));

                foreach ($stage->lines as $line) {
                    $newStage->lines()->create($line->only('material_id', 'qty', 'wastage_pct'));
                }
            }

            return $copy;
        });

        $this->toast("Copied to {$copy->code}. No stock or history was copied.");

        return to_route('components.show', $copy);
    }

    public function destroyLine(StageLine $line): RedirectResponse
    {
        $line->delete();

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function lineRules(Request $request): array
    {
        $data = $request->validate([
            'material_id' => ['required', 'exists:materials,id'],
            'qty' => ['required', 'numeric', 'gt:0'],
            'wastage_pct' => ['nullable', 'numeric', 'min:0', 'max:1000'],
        ]);
        $data['wastage_pct'] ??= 0;

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Component $component = null): array
    {
        $data = $request->validate([
            'category_id' => ['nullable', 'exists:categories,id'],
            'code' => ['required', 'string', 'max:50', Rule::unique('components')->ignore($component)],
            'name' => ['required', 'string', 'max:255'],
            'kit_group' => ['sometimes', 'nullable', Rule::in(KitGroups::ALL)],
            'yield_qty' => ['required', 'numeric', 'gt:0'],
            'wastage_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            'keeps_stock' => ['boolean'],
            'reorder_level' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'active' => ['boolean'],
        ]);
        $data['reorder_level'] ??= 0;

        return $data;
    }
}
