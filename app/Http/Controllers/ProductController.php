<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductOption;
use App\Services\Costing;
use App\Services\KitCounts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sellable kits (your figure sets). Each kit has options built from components and materials.
 */
class ProductController extends Controller
{
    public function index(Request $request): Response
    {
        $products = Product::query()
            ->with('category')
            ->withCount('options')
            ->when($request->integer('category'), fn ($query, $id) => $query->where('category_id', $id))
            ->orderBy('code')
            ->get()
            ->map(fn (Product $product) => [
                ...$product->only('id', 'code', 'name', 'active', 'options_count'),
                'category' => $product->category?->name,
            ]);

        return Inertia::render('products/index', [
            'products' => $products,
            'categories' => Category::query()->orderBy('sort')->get(['id', 'name', 'code_prefix']),
            'filters' => ['category' => $request->integer('category') ?: null],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $product = Product::query()->create($this->validated($request));
        $this->toast('Kit created. Add its products.');

        return to_route('products.show', $product);
    }

    public function show(Product $product, Costing $costing): Response
    {
        $product->load('category', 'options.parts.material', 'options.parts.component.stages.lines.material', 'options.courierPacking.stages.lines.material');

        return Inertia::render('products/show', [
            'product' => $product->only('id', 'code', 'name', 'category_id', 'notes', 'active') + ['category' => $product->category?->name],
            'options' => $product->options->map(fn ($option) => [
                ...$option->only('id', 'code', 'name', 'retail_price', 'wholesale_price', 'active', 'figures_per_kit', 'paint_pots_per_kit'),
                'cost' => round($costing->optionCost($option), 2),
                'cost_courier' => round($costing->optionCostWithCourier($option), 2),
            ]),
            'categories' => Category::query()->orderBy('sort')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, Product $product, KitCounts $counts): RedirectResponse
    {
        $product->update($this->validated($request, $product));

        if ($product->wasChanged('category_id')) {
            $product->options()->get()->each(fn (ProductOption $option) => $counts->sync($option));
        }

        $this->toast('Kit updated.');

        return back();
    }

    public function destroy(Product $product): RedirectResponse
    {
        $response = $this->deleteOrRefuse(fn () => $product->delete(), 'Kit');

        return $product->exists ? $response : to_route('products.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Product $product = null): array
    {
        return $request->validate([
            'category_id' => ['required', 'exists:categories,id'],
            'code' => ['required', 'string', 'max:50', Rule::unique('products')->ignore($product)],
            'name' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'active' => ['boolean'],
        ]);
    }
}
