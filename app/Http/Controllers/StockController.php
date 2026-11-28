<?php

namespace App\Http\Controllers;

use App\Models\Component;
use App\Models\PackRecord;
use App\Models\ProductOption;
use App\Models\StockMovement;
use App\Services\AssignmentService;
use App\Services\Costing;
use App\Services\KitStockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

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
            'packRecords' => PackRecord::query()->with('option:id,code,name')->latest('packed_on')->latest('id')->limit(40)->get()
                ->map(fn (PackRecord $record) => [
                    ...$record->only('id', 'qty', 'qty_remaining', 'unit_cost', 'is_legacy', 'note'),
                    'packed_on' => $record->packed_on->toDateString(),
                    'option' => $record->option?->code,
                    'contents' => collect($record->contents ?? [])->map(fn ($line) => "{$line['qty']} × {$line['name']}")->implode(', '),
                ]),
            'legacyQty' => (float) PackRecord::query()->where('is_legacy', true)->sum('qty_remaining'),
            'adjustReasons' => KitStockService::ADJUST_REASONS,
        ]);
    }

    public function make(Request $request, Component $component, KitStockService $kits): RedirectResponse
    {
        $data = $request->validate([
            'qty' => ['required', 'numeric', 'gt:0'],
            'made_on' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        if (! $component->keeps_stock) {
            return $this->refuse("{$component->code} does not keep stock. Turn on \"Keeps own stock\" first.");
        }

        $kits->make($component, (float) $data['qty'], Carbon::parse($data['made_on']), $data['note'] ?? null);
        $this->toast($component->uses_materials_when_made
            ? "Added {$data['qty']} × {$component->name} to stock. The paint and pots used are taken out of stock now."
            : "Added {$data['qty']} × {$component->name} to stock. Materials are deducted when it is packed or sold.");

        return back();
    }

    /**
     * Pack kits. Saying which figure sets and paint sets went in is optional: those leave stock
     * now and later sales of these kits use them without taking stock again.
     */
    public function pack(Request $request, ProductOption $option, KitStockService $kits, AssignmentService $assignments): RedirectResponse
    {
        $data = $this->qtyRules($request, 'packed_on');
        $rows = $request->validate([
            'assign' => ['nullable', 'array'],
            'assign.figures' => ['nullable', 'array'],
            'assign.figures.*.combo_id' => ['nullable', 'integer', 'exists:figure_combos,id'],
            'assign.figures.*.designs' => ['nullable', 'array'],
            'assign.figures.*.designs.*' => ['integer', 'min:0', 'max:12'],
            'assign.figures.*.qty' => ['required', 'numeric', 'gt:0'],
            'assign.paint' => ['nullable', 'array'],
            'assign.paint.*.component_id' => ['required', 'integer', 'exists:components,id'],
            'assign.paint.*.qty' => ['required', 'numeric', 'gt:0'],
        ])['assign'] ?? [];

        try {
            DB::transaction(function () use ($kits, $assignments, $option, $data, $rows) {
                $record = $kits->pack($option, (float) $data['qty'], Carbon::parse($data['packed_on']), $data['note'] ?? null);
                $assignments->recordForPacking($record, $rows);
            });
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['assign' => $exception->getMessage()]);
        }

        $this->toast("Packed {$data['qty']} × {$option->code}.");

        return back();
    }

    public function unpack(Request $request, ProductOption $option, KitStockService $kits, AssignmentService $assignments): RedirectResponse
    {
        $data = $this->qtyRules($request, 'packed_on');

        if ($data['qty'] > $option->stock) {
            return $this->refuse("Only {$option->stock} packed {$option->code} kits in stock.");
        }

        $result = $kits->unpack($option, (float) $data['qty'], Carbon::parse($data['packed_on']), $data['note'] ?? null);
        $assignments->shrinkPacked($option);

        if ($result['unknown_qty'] > 0) {
            $this->toast("Unpacked {$data['qty']} × {$option->code}. {$result['unknown_qty']} of them were packed before contents were recorded, so nothing could be restored for those: their original contents are unknown.", 'warning');
        } else {
            $this->toast("Unpacked {$data['qty']} × {$option->code}. The saved contents are back in stock.");
        }

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
