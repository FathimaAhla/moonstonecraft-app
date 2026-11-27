<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Component;
use App\Models\Material;
use App\Models\OptionPart;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\Setting;
use App\Services\AssignmentService;
use App\Services\ContainerService;
use App\Services\Costing;
use App\Services\KitCounts;
use App\Services\KitGroups;
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
    /**
     * Every product you sell (a kit option such as AST-2F-3P-1B) with its prices and stock,
     * filtered by category, kit, active and low stock.
     */
    public function index(Request $request, KitCounts $counts, KitStockService $kits): Response
    {
        $filters = [
            'category' => $request->integer('category') ?: null,
            'kit' => $request->integer('kit') ?: null,
            'status' => in_array($request->string('status')->toString(), ['active', 'inactive', 'all'], true) ? $request->string('status')->toString() : 'active',
            'low' => $request->boolean('low'),
        ];

        $rows = ProductOption::query()->with('product.category', 'parts.material', 'parts.component.stages.lines.material')
            ->when($filters['category'], fn ($query, $id) => $query->whereHas('product', fn ($product) => $product->where('category_id', $id)))
            ->when($filters['kit'], fn ($query, $id) => $query->where('product_id', $id))
            ->when($filters['status'] !== 'all', fn ($query) => $query->where('active', $filters['status'] === 'active'))
            ->when($filters['low'], fn ($query) => $query->whereColumn('stock', '<=', 'reorder_level'))
            ->orderBy('code')->get()
            ->map(function (ProductOption $option) use ($counts, $kits) {
                $recipe = $counts->fromRecipe($option);

                return [
                    ...$option->only('id', 'code', 'name', 'retail_price', 'wholesale_price', 'stock', 'reorder_level', 'active'),
                    'kit_id' => $option->product_id,
                    'kit' => $option->product?->name,
                    'category' => $option->product?->category?->name,
                    'figures' => $recipe['figures'],
                    'pots' => $recipe['pots'],
                    'brushes' => $recipe['brushes'],
                    'low' => $option->stock <= $option->reorder_level,
                    'can_make' => $kits->canMakeOption($option),
                ];
            });

        return Inertia::render('options/index', [
            'products' => $rows,
            'categories' => Category::query()->orderBy('sort')->get(['id', 'name']),
            'kits' => Product::query()->orderBy('name')->get(['id', 'name', 'category_id']),
            'filters' => $filters,
        ]);
    }

    /**
     * Look up a price without starting a sale: pick a kit, then how many figures, pots and
     * brushes, and see the matching product's prices and stock.
     */
    public function priceCheck(KitCounts $counts, KitStockService $kits): Response
    {
        return Inertia::render('options/price-check', [
            'products' => ProductOption::query()->with('product', 'parts.material', 'parts.component.stages.lines.material')
                ->where('active', true)->orderBy('code')->get()
                ->map(fn (ProductOption $option) => [
                    ...$option->only('id', 'code', 'name', 'retail_price', 'wholesale_price', 'stock'),
                    'kit_id' => $option->product_id,
                    ...$counts->fromRecipe($option),
                    'can_make' => $kits->canMakeOption($option)['can_make'],
                ])->values(),
            'categories' => Category::query()->orderBy('sort')->get(['id', 'name']),
            'kits' => Product::query()->where('active', true)->whereHas('options', fn ($query) => $query->where('active', true))
                ->orderBy('name')->get(['id', 'name', 'category_id']),
        ]);
    }

    public function create(Request $request, Product $product, Costing $costing): Response
    {
        $source = $request->filled('copy') ? $product->options()->with('parts')->findOrFail($request->integer('copy')) : null;

        return Inertia::render('options/form', [
            'product' => $product->only('id', 'code', 'name'),
            'option' => null,
            'copyFrom' => $source ? $this->formData($source) : null,
            ...$this->builderData($costing),
        ]);
    }

    public function edit(ProductOption $option, Costing $costing): Response
    {
        $option->load('product', 'parts');

        return Inertia::render('options/form', [
            'product' => $option->product->only('id', 'code', 'name'),
            'option' => $this->formData($option),
            'copyFrom' => null,
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
        $this->toast('Product created.');

        return to_route('options.show', $option);
    }

    public function show(ProductOption $option, Costing $costing, KitStockService $kits, AssignmentService $assignments, KitCounts $counts): Response
    {
        $canMake = $kits->canMakeOption($option);

        $option->load('product.category', 'parts.material', 'parts.component.stages.lines.material', 'courierPacking.stages.lines.material');

        return Inertia::render('options/show', [
            'option' => $option->only('id', 'code', 'name', 'retail_price', 'wholesale_price', 'courier_packing_component_id', 'courier_fee_estimate', 'stock', 'reorder_level', 'active', 'figures_per_kit', 'paint_pots_per_kit') + [
                'kit_name' => $option->product->name,
                'picks' => KitCounts::picksFiguresAndPaint($option),
                'brushes_per_kit' => $counts->fromRecipe($option)['brushes'],
            ],
            'canMake' => $canMake,
            'pickable' => $assignments->pickable($option),
            'packedUnrecorded' => $assignments->packedUnrecorded($option),
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
        $this->toast('Product saved.');

        return $request->has('parts') ? to_route('options.show', $option) : back();
    }

    public function destroy(ProductOption $option): RedirectResponse
    {
        $productId = $option->product_id;
        $this->deleteOrRefuse(fn () => $option->delete(), 'Product');

        return $option->exists ? back() : to_route('products.show', $productId);
    }

    public function storePart(Request $request, ProductOption $option, KitCounts $counts): RedirectResponse
    {
        $data = $request->validate([
            'material_id' => ['nullable', 'required_without:component_id', 'exists:materials,id'],
            'component_id' => ['nullable', 'required_without:material_id', 'exists:components,id'],
            'qty' => ['required', 'numeric', 'gt:0'],
        ]);
        $option->parts()->create($data);
        $counts->sync($option);

        return back();
    }

    public function destroyPart(OptionPart $part, KitCounts $counts): RedirectResponse
    {
        $part->delete();
        $counts->sync($part->option);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(ProductOption $option): array
    {
        return $option->only('id', 'code', 'name', 'retail_price', 'wholesale_price', 'courier_packing_component_id', 'courier_fee_estimate', 'active') + [
            'parts' => $option->parts->map(fn (OptionPart $part) => [
                'kind' => $part->component_id ? 'component' : 'material',
                'id' => $part->component_id ?? $part->material_id,
                'qty' => $part->qty,
            ]),
        ];
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
                'group' => KitGroups::resolve($component->kit_group, $component->name),
                'unit_cost' => round($costing->componentUnitCost($component), 4),
                'stages' => collect($costing->componentBreakdown($component)['stages'])
                    ->map(fn (array $stage) => ['name' => $stage['name'], 'cost' => $component->yield_qty > 0 ? round($stage['cost'] / $component->yield_qty, 4) : 0])
                    ->all(),
            ])->all();

        return [
            'components' => $components,
            'materials' => Material::query()->orderBy('name')->get()->map(fn (Material $material) => [
                'id' => $material->id,
                'name' => $material->name,
                'unit' => $material->unit,
                'unit_cost' => $material->unit_cost,
                'group' => KitGroups::resolve($material->kit_group, $material->name, ! $material->is_purchasable),
                'measured_qty' => $material->track_by_container ? (app(ContainerService::class)->measured($material)['qty_per_kit'] ?? null) : null,
            ])->all(),
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

        app(KitCounts::class)->sync($option);
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
