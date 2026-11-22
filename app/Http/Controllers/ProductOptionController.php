<?php

namespace App\Http\Controllers;

use App\Models\Component;
use App\Models\Material;
use App\Models\OptionPart;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\Setting;
use App\Services\Costing;
use App\Services\KitStockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProductOptionController extends Controller
{
    public function create(Product $product, Costing $costing): Response
    {
        return Inertia::render('options/form', [
            'product' => $product->only('id', 'code', 'name'),
            'option' => null,
            ...$this->builderData($costing),
        ]);
    }

    public function edit(ProductOption $option, Costing $costing): Response
    {
        $option->load('product', 'parts');

        return Inertia::render('options/form', [
            'product' => $option->product->only('id', 'code', 'name'),
            'option' => $option->only('id', 'code', 'name', 'retail_price', 'wholesale_price', 'courier_packing_component_id', 'courier_fee_estimate', 'active') + [
                'parts' => $option->parts->map(fn (OptionPart $part) => [
                    'kind' => $part->component_id ? 'component' : 'material',
                    'id' => $part->component_id ?? $part->material_id,
                    'qty' => $part->qty,
                ]),
            ],
            ...$this->builderData($costing),
        ]);
    }

    public function store(Request $request, Product $product): RedirectResponse
    {
        $option = DB::transaction(function () use ($request, $product) {
            $option = $product->options()->create($this->validated($request));
            $this->syncParts($request, $option);

            return $option;
        });
        $this->toast('Option created.');

        return to_route('options.show', $option);
    }

    public function show(ProductOption $option, Costing $costing, KitStockService $kits): Response
    {
        $option->load('product.category', 'parts.material', 'parts.component.stages.lines.material', 'courierPacking.stages.lines.material');

        return Inertia::render('options/show', [
            'option' => $option->only('id', 'code', 'name', 'retail_price', 'wholesale_price', 'courier_packing_component_id', 'courier_fee_estimate', 'stock', 'reorder_level', 'active'),
            'canMake' => $kits->canMakeOption($option),
            'movements' => $option->movements()->latest('moved_on')->latest('id')->limit(100)->get(),
            'adjustReasons' => KitStockService::ADJUST_REASONS,
            'product' => $option->product->only('id', 'code', 'name'),
            'parts' => $option->parts->map(fn (OptionPart $part) => [
                'id' => $part->id,
                'type' => $part->component_id ? 'component' : 'material',
                'name' => $part->component ? "{$part->component->code} {$part->component->name}" : $part->material?->name,
                'unit' => $part->material?->unit ?? 'pc',
                'qty' => $part->qty,
                'unit_cost' => round($costing->partUnitCost($part), 4),
                'cost' => round($part->qty * $costing->partUnitCost($part), 4),
            ]),
            'pricing' => $costing->suggest($option),
            'courierExtra' => round($costing->courierExtraCost($option), 4),
            'materials' => Material::query()->orderBy('name')->get(['id', 'name', 'unit', 'unit_cost']),
            'components' => Component::query()->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function update(Request $request, ProductOption $option): RedirectResponse
    {
        DB::transaction(function () use ($request, $option) {
            $option->update($this->validated($request, $option));
            $this->syncParts($request, $option);
        });
        $this->toast('Option saved.');

        return $request->has('parts') ? to_route('options.show', $option) : back();
    }

    public function destroy(ProductOption $option): RedirectResponse
    {
        $productId = $option->product_id;
        $this->deleteOrRefuse(fn () => $option->delete(), 'Option');

        return $option->exists ? back() : to_route('products.show', $productId);
    }

    public function storePart(Request $request, ProductOption $option): RedirectResponse
    {
        $data = $request->validate([
            'material_id' => ['nullable', 'required_without:component_id', 'exists:materials,id'],
            'component_id' => ['nullable', 'required_without:material_id', 'exists:components,id'],
            'qty' => ['required', 'numeric', 'gt:0'],
        ]);
        $option->parts()->create($data);

        return back();
    }

    public function destroyPart(OptionPart $part): RedirectResponse
    {
        $part->delete();

        return back();
    }

    /**
     * Component estimates and materials with their current unit cost, for the kit builder.
     *
     * @return array{components: list<array<string, mixed>>, materials: mixed, minMargin: float, priceStep: int}
     */
    private function builderData(Costing $costing): array
    {
        $components = Component::query()->with('stages.lines.material')->orderBy('code')->get()
            ->map(fn (Component $component) => [
                'id' => $component->id,
                'code' => $component->code,
                'name' => $component->name,
                'unit_cost' => round($costing->componentUnitCost($component), 4),
                'stages' => collect($costing->componentBreakdown($component)['stages'])
                    ->map(fn (array $stage) => ['name' => $stage['name'], 'cost' => $component->yield_qty > 0 ? round($stage['cost'] / $component->yield_qty, 4) : 0])
                    ->all(),
            ])->all();

        return [
            'components' => $components,
            'materials' => Material::query()->orderBy('name')->get(['id', 'name', 'unit', 'unit_cost']),
            'minMargin' => (float) Setting::value('min_margin_pct') / 100,
            'priceStep' => max(1, (int) Setting::value('price_step')),
        ];
    }

    /**
     * Replace the option's parts when the kit builder sends them.
     */
    private function syncParts(Request $request, ProductOption $option): void
    {
        if (! $request->has('parts')) {
            return;
        }

        $parts = $request->validate([
            'parts' => ['array'],
            'parts.*.kind' => ['required', Rule::in(['component', 'material'])],
            'parts.*.id' => ['required', 'integer'],
            'parts.*.qty' => ['required', 'numeric', 'gt:0'],
        ])['parts'] ?? [];

        foreach ($parts as $i => $part) {
            $table = $part['kind'] === 'component' ? 'components' : 'materials';
            if (! DB::table($table)->where('id', $part['id'])->exists()) {
                throw ValidationException::withMessages(["parts.{$i}.id" => 'Choose an item.']);
            }
        }

        $option->parts()->delete();
        foreach ($parts as $part) {
            $option->parts()->create([
                'component_id' => $part['kind'] === 'component' ? $part['id'] : null,
                'material_id' => $part['kind'] === 'material' ? $part['id'] : null,
                'qty' => $part['qty'],
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?ProductOption $option = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', Rule::unique('product_options')->ignore($option)],
            'name' => ['required', 'string', 'max:255'],
            'retail_price' => ['required', 'numeric', 'min:0'],
            'wholesale_price' => ['nullable', 'numeric', 'min:0'],
            'courier_packing_component_id' => ['nullable', 'exists:components,id'],
            'courier_fee_estimate' => ['nullable', 'numeric', 'min:0'],
            'reorder_level' => ['nullable', 'numeric', 'min:0'],
            'active' => ['boolean'],
        ]);
        $data['courier_fee_estimate'] ??= 0;
        $data['reorder_level'] ??= 0;

        return $data;
    }
}
