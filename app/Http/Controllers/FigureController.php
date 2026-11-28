<?php

namespace App\Http\Controllers;

use App\Models\Component;
use App\Models\FigureCombo;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\StockMovement;
use App\Services\AssignmentService;
use App\Services\FigureService;
use App\Services\KitCounts;
use App\Services\KitStockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Figure stock by category and kit (DIY painting kit > Vehicle Kit: Car, Bike, Bus...), saved
 * figure sets, kits still waiting for figures, and what sold.
 */
class FigureController extends Controller
{
    public function index(FigureService $figures, AssignmentService $assignments): Response
    {
        $soldDesign = $assignments->figuresSoldByDesign();
        $soldCombo = $assignments->soldBy('figures');
        $designs = $figures->designs()->groupBy('product_id');
        $combos = $figures->combos()->groupBy('product_id');
        $damaged = $this->damaged();

        $designRow = fn (Component $c) => [
            ...$c->only('id', 'code', 'name', 'stock', 'reorder_level', 'product_id'),
            'sold_month' => round($soldDesign[$c->id]['month'] ?? 0, 3),
            'sold' => round($soldDesign[$c->id]['all'] ?? 0, 3),
            'damaged_month' => $damaged[$c->id]['month'] ?? 0,
            'damaged' => $damaged[$c->id]['all'] ?? 0,
        ];

        $sizes = ProductOption::query()->where('active', true)->where('figures_per_kit', '>', 0)->get(['product_id', 'figures_per_kit'])
            ->groupBy('product_id')->map(fn ($options) => $options->pluck('figures_per_kit')->unique()->sort()->values());

        $kits = $this->figureKits()->filter(fn (Product $kit) => $kit->category?->kit_picks === KitCounts::PICKS_FIGURES_PAINT || $designs->has($kit->id));

        return Inertia::render('figures/index', [
            'categories' => $kits->groupBy(fn (Product $kit) => $kit->category?->name ?? 'No category')
                ->map(fn ($kitsInCategory, string $category) => [
                    'name' => $category,
                    'kits' => $kitsInCategory->map(fn (Product $kit) => [
                        ...$kit->only('id', 'code', 'name'),
                        'sizes' => $sizes[$kit->id] ?? [],
                        'designs' => ($designs[$kit->id] ?? collect())->map($designRow)->values(),
                        'combos' => ($combos[$kit->id] ?? collect())->map(fn (FigureCombo $combo) => $figures->summary($combo) + [
                            'sold_month' => $soldCombo[$combo->id]['month'] ?? 0,
                            'sold' => $soldCombo[$combo->id]['all'] ?? 0,
                            'damaged_month' => $damaged[$combo->component_id]['month'] ?? 0,
                            'damaged' => $damaged[$combo->component_id]['all'] ?? 0,
                        ])->values(),
                    ])->values(),
                ])->values(),
            'unlinked' => $figures->unlinkedDesigns()->map(fn (Component $c) => $designRow($c) + ['family' => $c->figure_family])->values(),
            'kitChoices' => $this->figureKits()->map(fn (Product $kit) => ['id' => $kit->id, 'label' => $kit->name, 'category' => $kit->category?->name])->values(),
            'waiting' => $assignments->waiting('figures'),
            'packedUnrecorded' => ProductOption::query()->with('product')->where('figures_per_kit', '>', 0)->where('stock', '>', 0)->orderBy('code')->get()
                ->map(fn (ProductOption $option) => ['id' => $option->id, 'code' => $option->code, 'name' => trim($option->product?->name.' - '.$option->name, ' -'), 'qty' => $assignments->packedUnrecorded($option)['figures']])
                ->filter(fn (array $row) => $row['qty'] > 0)->values(),
            'adjustReasons' => KitStockService::ADJUST_REASONS,
        ]);
    }

    public function storeDesign(Request $request, FigureService $figures): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'name' => ['required', 'string', 'max:255'],
        ]);
        $kit = Product::query()->findOrFail($data['product_id']);
        $figures->createDesign($kit, trim($data['name']));
        $this->toast("{$data['name']} added to {$kit->name}. Use Made to add its count.");

        return back();
    }

    /**
     * Move a figure design to another kit. Figure sets already saved keep their designs.
     */
    public function updateKit(Request $request, Component $component): RedirectResponse
    {
        $component->update($request->validate(['product_id' => ['required', 'exists:products,id']]));
        $this->toast("{$component->name} moved.");

        return back();
    }

    public function storeCombo(Request $request, FigureService $figures): RedirectResponse
    {
        $data = $request->validate([
            'designs' => ['required', 'array'],
            'designs.*' => ['integer', 'min:0', 'max:12'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $combo = $figures->combo($data['designs'], $data['name'] ?? null);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['designs' => $exception->getMessage()]);
        }

        $this->toast("Combination {$combo->name} saved.");

        return back();
    }

    /**
     * You put together sets of a combination: set stock up, single figures down.
     */
    public function makeSets(Request $request, FigureCombo $combo, FigureService $figures): RedirectResponse
    {
        $data = $request->validate([
            'qty' => ['required', 'numeric', 'gt:0'],
            'made_on' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $figures->makeSets($combo, (float) $data['qty'], Carbon::parse($data['made_on']), $data['note'] ?? null);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['qty' => $exception->getMessage()]);
        }

        $this->toast("Made {$data['qty']} × {$combo->name}. The single figures were taken out of stock.");

        return back();
    }

    public function destroyCombo(FigureCombo $combo): RedirectResponse
    {
        $combo->delete();
        $this->toast('Combination removed. Sales already made keep their figures.');

        return back();
    }

    /**
     * Kits in category order, then by name.
     *
     * @return Collection<int, Product>
     */
    private function figureKits(): Collection
    {
        return Product::query()->with('category')->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->orderBy('categories.sort')->orderBy('products.name')->select('products.*')->get();
    }

    /**
     * Figures and sets written off as damaged or missing, per part, this month and all time.
     *
     * @return array<int, array{month: float, all: float}>
     */
    private function damaged(): array
    {
        $monthStart = now()->startOfMonth()->toDateString();

        return StockMovement::query()->whereNotNull('component_id')->whereIn('reason', ['damaged', 'missing'])->where('qty', '<', 0)
            ->get(['component_id', 'qty', 'moved_on'])->groupBy('component_id')
            ->map(fn ($rows) => [
                'all' => round(-(float) $rows->sum('qty'), 3),
                'month' => round(-(float) $rows->filter(fn ($row) => $row->moved_on->toDateString() >= $monthStart)->sum('qty'), 3),
            ])->all();
    }
}
