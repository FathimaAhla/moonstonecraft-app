<?php

namespace App\Services;

use App\Models\Component;
use App\Models\FigureCombo;
use App\Models\PackRecord;
use App\Models\ProductOption;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleItemAssignment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Which figure sets and paint sets went into kits.
 *
 * A kit says only how many: "3 figures from Vehicle" and "a 3-pot paint set". Saying which ones
 * is always optional and can happen when packing, when selling, or later on the sale. Stock
 * leaves exactly once, at the moment it is said:
 * - when packing, the sets leave stock and the packing keeps a "packed with" row;
 * - when selling kits that came from packed stock, the sale first takes from those packed rows
 *   (no second deduction), then from stock: made-up sets first, then single figures.
 * Kits with nothing said yet are "not chosen" and have taken nothing.
 */
class AssignmentService
{
    public function __construct(private Costing $costing, private KitStockService $kits, private FigureService $figures, private PaintSetService $paintSets) {}

    public static function needs(?ProductOption $option, string $type): bool
    {
        return $option !== null && ($type === 'figures' ? $option->figures_per_kit > 0 : $option->paint_pots_per_kit > 0);
    }

    /**
     * Kits of this line still without figures (or paint).
     */
    public function remaining(SaleItem $item, string $type): float
    {
        if (! self::needs($item->option, $type)) {
            return 0.0;
        }

        $assigned = (float) $item->assignments()->where('type', $type)->sum('qty');

        return round(max($item->qty - $assigned, 0), 3);
    }

    /**
     * Give $qty kits of a sold line a figure combination or paint set.
     */
    public function add(SaleItem $item, string $type, FigureCombo|Component $what, float $qty): void
    {
        DB::transaction(function () use ($item, $type, $what, $qty) {
            $item = SaleItem::query()->with('option', 'sale')->lockForUpdate()->findOrFail($item->id);
            $this->check($item->option, $type, $what);

            if ($qty <= 0 || $qty > $this->remaining($item, $type) + 1e-9) {
                throw new InvalidArgumentException('Only '.$this->remaining($item, $type).' kits of this line are not chosen yet.');
            }

            $sale = $item->sale;
            $left = $qty - $this->takeFromPacked($item, $type, $what, $qty);

            if ($left > 1e-9) {
                $taken = $this->takeFromStock($type, $what, $left, $sale, Carbon::parse($sale->sold_on));
                $item->assignments()->create([
                    'type' => $type,
                    'figure_combo_id' => $what instanceof FigureCombo ? $what->id : null,
                    'component_id' => $what instanceof Component ? $what->id : null,
                    'label' => $what->name,
                    'qty' => round($left, 3),
                    'unit_cost' => $taken['unit_cost'],
                    'usage' => $taken['usage'],
                ]);
                $this->addCost($item, $sale, $taken['unit_cost'] * $left);
            }
        });
    }

    /**
     * Record which sets went into a packing: they leave stock now and the packing keeps
     * "packed with" rows, which sales of these kits use and unpacking puts back.
     *
     * @param  array{figures?: list<array<string, mixed>>, paint?: list<array<string, mixed>>}  $rows
     */
    public function recordForPacking(PackRecord $record, array $rows): void
    {
        $option = $record->option;
        $on = Carbon::parse($record->packed_on);

        foreach (SaleItemAssignment::TYPES as $type) {
            $packed = 0.0;

            foreach ($rows[$type] ?? [] as $row) {
                $qty = (float) $row['qty'];
                $what = $this->resolve($type, $row);
                $this->check($option, $type, $what);
                $packed += $qty;

                if ($packed > $record->qty + 1e-9) {
                    throw new InvalidArgumentException('The rows add up to more kits than you packed.');
                }

                $taken = $this->takeFromStock($type, $what, $qty, $record, $on);
                $record->assignments()->create([
                    'type' => $type,
                    'figure_combo_id' => $what instanceof FigureCombo ? $what->id : null,
                    'component_id' => $what instanceof Component ? $what->id : null,
                    'label' => $what->name,
                    'qty' => $qty,
                    'qty_remaining' => $qty,
                    'unit_cost' => $taken['unit_cost'],
                    'usage' => $taken['usage'],
                ]);
            }
        }
    }

    /**
     * After unpacking: "packed with" rows can only cover kits still packed, plus packed kits that
     * were sold without saying which sets they had. Anything above that was in the opened kits,
     * so it goes back into stock (newest packing first).
     */
    public function shrinkPacked(ProductOption $option): void
    {
        $option = $option->fresh();

        foreach (SaleItemAssignment::TYPES as $type) {
            $rows = $this->packedRows($option, $type)->reverse();
            $soldUnpicked = SaleItem::query()->with('assignments')->where('product_option_id', $option->id)->get()
                ->sum(fn (SaleItem $item) => max((float) ($item->contents['packed_qty'] ?? 0) - (float) $item->assignments->where('type', $type)->whereNotNull('pack_record_id')->sum('qty'), 0));
            $excess = (float) $rows->sum('qty_remaining') - max($option->stock, 0) - $soldUnpicked;

            foreach ($rows as $row) {
                if ($excess <= 1e-9) {
                    break;
                }

                $back = min($row->qty_remaining, $excess);
                $this->kits->restoreUsage($this->scale($row->usage ?? [], $back / max($row->qty, 1e-9)), 'unpacking', $row->packRecord);
                $row->update(['qty_remaining' => round($row->qty_remaining - $back, 3)]);
                $excess -= $back;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $usage
     * @return array<string, mixed>
     */
    private function scale(array $usage, float $factor): array
    {
        $scaled = ['prededucted' => []];

        foreach ($usage as $kind => $items) {
            foreach ((array) $items as $id => $qty) {
                $scaled[$kind][$id] = round((float) $qty * $factor, 3);
            }
        }

        return $scaled;
    }

    /**
     * Take a row off a sold line: stock it took goes back, or a packed row gets its kits back.
     */
    public function remove(SaleItemAssignment $assignment): void
    {
        DB::transaction(function () use ($assignment) {
            $assignment = SaleItemAssignment::query()->with('item.sale')->lockForUpdate()->findOrFail($assignment->id);

            if (! $assignment->item) {
                throw new InvalidArgumentException('Packed rows are changed by unpacking the kits.');
            }

            $this->undo($assignment, $assignment->item->sale);
            $this->addCost($assignment->item, $assignment->item->sale, -$assignment->unit_cost * $assignment->qty);
            $assignment->delete();
        });
    }

    /**
     * Rows typed on the sale form: [{combo_id | designs, qty}] for figures, [{component_id, qty}] for paint.
     *
     * @param  array{figures?: list<array<string, mixed>>, paint?: list<array<string, mixed>>}  $rows
     */
    public function recordForItem(SaleItem $item, array $rows): void
    {
        foreach (SaleItemAssignment::TYPES as $type) {
            foreach ($rows[$type] ?? [] as $row) {
                $this->add($item, $type, $this->resolve($type, $row), (float) $row['qty']);
            }
        }
    }

    /**
     * Put back everything a sold line's rows took (sale deleted).
     */
    public function restoreItem(SaleItem $item, Sale $sale): void
    {
        foreach ($item->assignments as $assignment) {
            $this->undo($assignment, $sale);
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function resolve(string $type, array $row): FigureCombo|Component
    {
        if ($type === 'paint') {
            return Component::query()->findOrFail($row['component_id'] ?? 0);
        }

        return ! empty($row['combo_id']) ? FigureCombo::query()->findOrFail($row['combo_id']) : $this->figures->combo($row['designs'] ?? []);
    }

    /**
     * A sold line as the sale page shows it: what it needs, its rows, and what can be picked.
     *
     * @return array<string, mixed>|null
     */
    public function itemPanel(SaleItem $item): ?array
    {
        $option = $item->option;

        if (! self::needs($option, 'figures') && ! self::needs($option, 'paint')) {
            return null;
        }

        $rows = fn (string $type) => $item->assignments->where('type', $type)->map(fn (SaleItemAssignment $a) => [...$a->only('id', 'label', 'qty'), 'from_packed' => $a->pack_record_id !== null])->values()->all();
        $assigned = fn (string $type) => (float) $item->assignments->where('type', $type)->sum('qty');

        return [
            'figures' => self::needs($option, 'figures') ? [
                'family' => $option->product?->name,
                'per_kit' => $option->figures_per_kit,
                'rows' => $rows('figures'),
                'remaining' => round(max($item->qty - $assigned('figures'), 0), 3),
            ] : null,
            'paint' => self::needs($option, 'paint') ? [
                'pots' => $option->paint_pots_per_kit,
                'rows' => $rows('paint'),
                'remaining' => round(max($item->qty - $assigned('paint'), 0), 3),
            ] : null,
        ];
    }

    /**
     * What can be picked for a kit: figure sets of its own kit and size, paint sets with its pot
     * count, and how many packed kits already hold each.
     *
     * @return array{figure_combos: list<array<string, mixed>>, designs: list<array<string, mixed>>, paint_sets: list<array<string, mixed>>}
     */
    public function pickable(ProductOption $option): array
    {
        $packed = $this->packedRows($option)->groupBy(fn (SaleItemAssignment $row) => $row->type.':'.($row->figure_combo_id ?? $row->component_id))
            ->map(fn ($rows) => round((float) $rows->sum('qty_remaining'), 3));

        return [
            'figure_combos' => self::needs($option, 'figures')
                ? $this->figures->combos($option->product_id, $option->figures_per_kit)
                    ->map(fn (FigureCombo $combo) => $this->figures->summary($combo) + ['packed' => $packed['figures:'.$combo->id] ?? 0])->values()->all()
                : [],
            'designs' => self::needs($option, 'figures')
                ? $this->figures->designs($option->product_id)->map(fn (Component $c) => ['id' => $c->id, 'name' => $c->name, 'stock' => $c->stock])->values()->all()
                : [],
            'paint_sets' => self::needs($option, 'paint')
                ? $this->paintSets->all()->filter(fn (Component $set) => $this->paintSets->potCount($set) === $option->paint_pots_per_kit)
                    ->map(fn (Component $set) => $this->paintSets->summary($set) + ['packed' => $packed['paint:'.$set->id] ?? 0])->values()->all()
                : [],
        ];
    }

    /**
     * Packed kits of an option whose figures or paint were not recorded at packing.
     *
     * @return array{figures: float, paint: float}
     */
    public function packedUnrecorded(ProductOption $option): array
    {
        $rows = $this->packedRows($option);
        $packed = max($option->stock, 0);

        return [
            'figures' => self::needs($option, 'figures') ? round(max($packed - (float) $rows->where('type', 'figures')->sum('qty_remaining'), 0), 3) : 0.0,
            'paint' => self::needs($option, 'paint') ? round(max($packed - (float) $rows->where('type', 'paint')->sum('qty_remaining'), 0), 3) : 0.0,
        ];
    }

    /**
     * Sold lines with kits still waiting for figures or paint, oldest sale first.
     *
     * @return list<array<string, mixed>>
     */
    public function waiting(?string $type = null): array
    {
        return SaleItem::query()->with('sale.customer:id,name', 'option', 'assignments')
            ->whereHas('option', fn ($query) => $query->where('figures_per_kit', '>', 0)->orWhere('paint_pots_per_kit', '>', 0))
            ->get()
            ->flatMap(fn (SaleItem $item) => collect($type ? [$type] : SaleItemAssignment::TYPES)
                ->filter(fn (string $t) => self::needs($item->option, $t))
                ->map(fn (string $t) => [
                    'sale_item_id' => $item->id,
                    'type' => $t,
                    'sale_id' => $item->sale_id,
                    'invoice_no' => $item->sale->invoice_no,
                    'sold_on' => $item->sale->sold_on->toDateString(),
                    'customer' => $item->sale->customer?->name,
                    'item' => $item->name,
                    'qty' => $item->qty,
                    'remaining' => round(max($item->qty - (float) $item->assignments->where('type', $t)->sum('qty'), 0), 3),
                ]))
            ->filter(fn (array $row) => $row['remaining'] > 0)
            ->sortBy('sold_on')->values()->all();
    }

    /**
     * Kits sold per figure combination or paint set, this month and all time.
     *
     * @return array<int, array{month: float, all: float}> combo or paint set id => counts
     */
    public function soldBy(string $type): array
    {
        $column = $type === 'figures' ? 'figure_combo_id' : 'component_id';
        $monthStart = now()->startOfMonth()->toDateString();

        return SaleItemAssignment::query()->where('type', $type)->whereNotNull($column)->whereNotNull('sale_item_id')
            ->join('sale_items', 'sale_items.id', '=', 'sale_item_assignments.sale_item_id')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->get(["sale_item_assignments.{$column} as ref", 'sale_item_assignments.qty', 'sales.sold_on'])
            ->groupBy('ref')
            ->map(fn ($rows) => [
                'all' => round((float) $rows->sum('qty'), 3),
                'month' => round((float) $rows->filter(fn ($row) => substr((string) $row->sold_on, 0, 10) >= $monthStart)->sum('qty'), 3),
            ])->all();
    }

    /**
     * Figures sold per design (from the combinations sold), this month and all time.
     *
     * @return array<int, array{month: float, all: float}>
     */
    public function figuresSoldByDesign(): array
    {
        $byCombo = $this->soldBy('figures');
        $designs = [];

        foreach (FigureCombo::query()->with('items')->whereIn('id', array_keys($byCombo))->get() as $combo) {
            foreach ($combo->items as $item) {
                foreach (['all', 'month'] as $period) {
                    $designs[$item->component_id][$period] = ($designs[$item->component_id][$period] ?? 0) + $item->qty * $byCombo[$combo->id][$period];
                }
            }
        }

        return $designs;
    }

    /**
     * Use "packed with" rows of this kit for sold kits that came from packed stock. Nothing
     * leaves stock again: it left when the kits were packed.
     */
    private function takeFromPacked(SaleItem $item, string $type, FigureCombo|Component $what, float $qty): float
    {
        $packedKits = (float) ($item->contents['packed_qty'] ?? 0);
        $alreadyFromPacked = (float) $item->assignments()->where('type', $type)->whereNotNull('pack_record_id')->sum('qty');
        $limit = min($qty, max($packedKits - $alreadyFromPacked, 0));
        $taken = 0.0;

        foreach ($this->packedRows($item->option, $type, $what) as $row) {
            if ($limit - $taken <= 1e-9) {
                break;
            }

            $use = min($row->qty_remaining, $limit - $taken);
            $row->update(['qty_remaining' => round($row->qty_remaining - $use, 3)]);
            $item->assignments()->create([
                'type' => $type,
                'pack_record_id' => $row->pack_record_id,
                'figure_combo_id' => $row->figure_combo_id,
                'component_id' => $row->component_id,
                'label' => $row->label,
                'qty' => round($use, 3),
                'unit_cost' => $row->unit_cost,
                'usage' => null,
            ]);
            $this->addCost($item, $item->sale, $row->unit_cost * $use);
            $taken += $use;
        }

        return $taken;
    }

    /**
     * Take sets out of stock for $kits kits: figure sets first, then single figures; paint sets.
     *
     * @return array{usage: array<string, mixed>, unit_cost: float}
     */
    private function takeFromStock(string $type, FigureCombo|Component $what, float $kits, Sale|PackRecord $ref, Carbon $on): array
    {
        if ($what instanceof FigureCombo) {
            $what->loadMissing('items');
            $parts = $this->figures->takeParts($what, $kits);
            $unitCost = round($this->costing->snapshot($this->figures->parts($what), 1)['cost'], 4);
        } else {
            $parts = [['component_id' => $what->id, 'material_id' => null, 'qty' => $kits]];
            $unitCost = round($this->costing->snapshot([['component_id' => $what->id, 'material_id' => null, 'qty' => 1]], 1)['cost'], 4);
        }

        $usage = $this->kits->plan($parts, 1)['usage'];
        $this->kits->deductUsage($usage, $ref instanceof Sale ? 'sale' : 'packing', $ref, $on);

        return ['usage' => $usage, 'unit_cost' => $unitCost];
    }

    /**
     * "Packed with" rows of a kit that still have kits left, oldest packing first.
     *
     * @return Collection<int, SaleItemAssignment>
     */
    private function packedRows(?ProductOption $option, ?string $type = null, FigureCombo|Component|null $what = null): Collection
    {
        if (! $option) {
            return collect();
        }

        return SaleItemAssignment::query()->whereNull('sale_item_id')->where('qty_remaining', '>', 0)
            ->whereHas('packRecord', fn ($query) => $query->where('product_option_id', $option->id))
            ->when($type, fn ($query) => $query->where('type', $type))
            ->when($what instanceof FigureCombo, fn ($query) => $query->where('figure_combo_id', $what->id))
            ->when($what instanceof Component, fn ($query) => $query->where('component_id', $what->id))
            ->orderBy('pack_record_id')->orderBy('id')->get();
    }

    private function undo(SaleItemAssignment $assignment, Sale $sale): void
    {
        if ($assignment->pack_record_id) {
            $packed = SaleItemAssignment::query()->whereNull('sale_item_id')->where('pack_record_id', $assignment->pack_record_id)
                ->where('type', $assignment->type)->where('figure_combo_id', $assignment->figure_combo_id)->where('component_id', $assignment->component_id)->first();
            $packed?->update(['qty_remaining' => round($packed->qty_remaining + $assignment->qty, 3)]);

            return;
        }

        $this->kits->restoreUsage($assignment->usage ?? [], 'adjustment', $sale);
    }

    private function check(?ProductOption $option, string $type, FigureCombo|Component $what): void
    {
        if (! self::needs($option, $type)) {
            throw new InvalidArgumentException($type === 'figures' ? 'This kit does not take chosen figures.' : 'This kit does not take a paint set.');
        }

        if ($type === 'figures') {
            if (! $what instanceof FigureCombo || $what->size !== $option->figures_per_kit) {
                throw new InvalidArgumentException("This kit takes {$option->figures_per_kit} figures.");
            }

            if ($what->product_id !== $option->product_id) {
                throw new InvalidArgumentException("This kit takes {$option->product?->name} figures.");
            }
        } elseif (! $what instanceof Component || $what->kit_group !== 'paint' || $this->paintSets->potCount($what) !== $option->paint_pots_per_kit) {
            throw new InvalidArgumentException("This kit takes a {$option->paint_pots_per_kit}-pot paint set.");
        }
    }

    private function addCost(SaleItem $item, Sale $sale, float $total): void
    {
        $item->update(['unit_cost' => round($item->unit_cost + $total / max($item->qty, 1e-9), 4)]);
        $sale->update(['cost_total' => round($sale->cost_total + $total, 2)]);
    }
}
