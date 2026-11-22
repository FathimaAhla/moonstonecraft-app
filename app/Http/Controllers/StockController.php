<?php

namespace App\Http\Controllers;

use App\Models\Component;
use App\Models\ProductOption;
use App\Models\StockMovement;
use App\Services\Costing;
use App\Services\KitStockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Part stock (figures, paint pots you make) and ready kit stock: make, pack, unpack, correct.
 */
class StockController extends Controller
{
    public function index(Costing $costing, KitStockService $kits): Response
    {
        $components = Component::query()->with('category', 'stages.lines.material')
            ->where('keeps_stock', true)->orderBy('code')->get()
            ->map(fn (Component $component) => [
                ...$component->only('id', 'code', 'name', 'stock', 'reorder_level'),
                'category' => $component->category?->name ?? 'No category',
                'unit_cost' => round($costing->componentUnitCost($component), 2),
                'has_recipe' => $component->stages->flatMap->lines->isNotEmpty(),
                'from_materials' => $kits->canMakeComponent($component),
            ]);

        $options = ProductOption::query()->with('product', 'parts.material', 'parts.component.stages.lines.material')
            ->where(fn ($q) => $q->where('active', true)->orWhere('stock', '!=', 0))->orderBy('code')->get()
            ->map(fn (ProductOption $option) => [
                ...$option->only('id', 'code', 'name', 'stock', 'reorder_level', 'retail_price'),
                'product' => $option->product?->name,
                'unit_cost' => round($costing->optionCost($option), 2),
                'can_make' => $kits->canMakeOption($option),
            ]);

        return Inertia::render('stock/index', [
            'components' => $components,
            'options' => $options,
            'movements' => StockMovement::query()->with('component:id,code,name', 'option:id,code,name')
                ->where(fn ($q) => $q->whereNotNull('component_id')->orWhereNotNull('product_option_id'))
                ->latest('moved_on')->latest('id')->limit(100)->get(),
            'adjustReasons' => KitStockService::ADJUST_REASONS,
        ]);
    }

    public function make(Request $request, Component $component, KitStockService $kits): RedirectResponse
    {
        $data = $request->validate([
            'qty' => ['required', 'numeric', 'gt:0'],
            'made_on' => ['required', 'date'],
            'use_materials' => ['boolean'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        if (! $component->keeps_stock) {
            return $this->refuse("{$component->code} does not keep stock. Turn on \"Keeps own stock\" first.");
        }

        $kits->make($component, (float) $data['qty'], Carbon::parse($data['made_on']), $request->boolean('use_materials'), $data['note'] ?? null);
        $this->toast("Added {$data['qty']} × {$component->name} to stock.");

        return back();
    }

    public function pack(Request $request, ProductOption $option, KitStockService $kits): RedirectResponse
    {
        $data = $this->qtyRules($request, 'packed_on');
        $kits->pack($option, (float) $data['qty'], Carbon::parse($data['packed_on']), $data['note'] ?? null);
        $this->toast("Packed {$data['qty']} × {$option->code}.");

        return back();
    }

    public function unpack(Request $request, ProductOption $option, KitStockService $kits): RedirectResponse
    {
        $data = $this->qtyRules($request, 'packed_on');

        if ($data['qty'] > $option->stock) {
            return $this->refuse("Only {$option->stock} packed {$option->code} kits in stock.");
        }

        $kits->unpack($option, (float) $data['qty'], Carbon::parse($data['packed_on']), $data['note'] ?? null);
        $this->toast("Unpacked {$data['qty']} × {$option->code}. Parts are back in stock.");

        return back();
    }

    public function adjustComponent(Request $request, Component $component, KitStockService $kits): RedirectResponse
    {
        return $this->adjust($request, $kits, 'components', $component->id);
    }

    public function adjustOption(Request $request, ProductOption $option, KitStockService $kits): RedirectResponse
    {
        return $this->adjust($request, $kits, 'options', $option->id);
    }

    /**
     * @param  'components'|'options'  $kind
     */
    private function adjust(Request $request, KitStockService $kits, string $kind, int $id): RedirectResponse
    {
        $data = $request->validate([
            'qty' => ['required', 'numeric', 'not_in:0'],
            'reason' => ['required', Rule::in(KitStockService::ADJUST_REASONS)],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $kits->adjust($kind, $id, (float) $data['qty'], $data['reason'], $data['note'] ?? null);
        $this->toast('Stock corrected.');

        return back();
    }

    /**
     * @return array{qty: float|string, note?: string|null}&array<string, mixed>
     */
    private function qtyRules(Request $request, string $dateField): array
    {
        return $request->validate([
            'qty' => ['required', 'numeric', 'gt:0'],
            $dateField => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
    }

    private function refuse(string $message): RedirectResponse
    {
        $this->toast($message, 'error');

        return back();
    }
}
