<?php

namespace App\Services;

use App\Models\Component;
use App\Models\Material;
use App\Models\OptionPart;
use App\Models\PackRecord;
use App\Models\ProductOption;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Making parts, packing kits, unpacking, and working out how much a sale takes from stock.
 *
 * Flow: buying and making parts never deduct material stock. Tracked materials leave stock
 * when a kit is packed, or when an unpacked/custom kit is sold. Parts that keep stock carry a
 * "prededucted" balance: stock made under the older flow whose materials were already deducted,
 * which is used up first so those materials are never deducted twice.
 *
 * Usage arrays are stock bags (materials / components / options) plus a `prededucted`
 * map of component_id => qty, all saved with the transaction that used them.
 */
class KitStockService
{
    /** Reasons you can pick when correcting part or kit stock by hand. */
    public const ADJUST_REASONS = ['adjustment', 'damaged', 'missing', 'found'];

    public function __construct(private Costing $costing, private StockService $stock) {}

    /**
     * You made $qty of a part (figures, paint pots). Its stock goes up. Materials are normally
     * NOT deducted: that happens when the part goes into a packed or sold kit. Parts marked
     * "uses materials when made" (paint sets) take their materials now instead, and that stock
     * is counted as prededucted so packing or selling it never deducts the paint a second time.
     */
    public function make(Component $component, float $qty, ?Carbon $on = null, ?string $note = null): void
    {
        $this->ensureKeepsStock($component);

        DB::transaction(function () use ($component, $qty, $on, $note) {
            if ($component->uses_materials_when_made) {
                $this->stock->deduct($this->costing->componentUsage($component, $qty), 'made', $component, $on);
                Component::query()->whereKey($component->id)->increment('prededucted_stock', $qty);
            }

            $this->stock->moveComponent($component->id, $qty, 'made', null, $on, $note);
        });
    }

    /**
     * Pack $qty kits: parts and tracked materials out, ready kit stock in, with a saved record
     * of the contents, usage and cost at this moment.
     */
    public function pack(ProductOption $option, float $qty, ?Carbon $on = null, ?string $note = null): PackRecord
    {
        return DB::transaction(function () use ($option, $qty, $on, $note) {
            $option = ProductOption::query()->lockForUpdate()->findOrFail($option->id);
            $this->reconcile($option);
            $parts = $this->optionParts($option);
            $plan = $this->plan($parts, $qty);

            $this->deductUsage($plan['usage'], 'packing', $option, $on);
            $this->stock->moveOption($option->id, $qty, 'packed', null, $on, $note);

            return PackRecord::query()->create([
                'product_option_id' => $option->id,
                'packed_on' => ($on ?? now())->toDateString(),
                'qty' => $qty,
                'qty_remaining' => $qty,
                'unit_cost' => round($plan['cost'] / max($qty, 1e-9), 4),
                'contents' => $plan['contents'],
                'usage' => $plan['usage'],
                'usage_remaining' => $plan['usage'],
                'note' => $note,
            ]);
        });
    }

    /**
     * Open $qty packed kits, newest packing first. What goes back into stock comes from the
     * saved packing records, never from the option's current recipe, and never more than was packed.
     *
     * @return array{restored_qty: float, unknown_qty: float}
     */
    public function unpack(ProductOption $option, float $qty, ?Carbon $on = null, ?string $note = null): array
    {
        return DB::transaction(function () use ($option, $qty, $on, $note) {
            $option = ProductOption::query()->lockForUpdate()->findOrFail($option->id);
            $this->reconcile($option);

            if ($qty > $option->stock + 1e-9) {
                throw new InvalidArgumentException("Only {$option->stock} packed {$option->code} kits in stock.");
            }

            $restored = 0.0;
            $unknown = 0.0;
            $left = $qty;

            $records = PackRecord::query()->where('product_option_id', $option->id)->where('qty_remaining', '>', 0)
                ->orderByDesc('packed_on')->orderByDesc('id')->lockForUpdate()->get();

            foreach ($records as $record) {
                if ($left <= 1e-9) {
                    break;
                }

                $take = min($left, $record->qty_remaining);
                $slice = $this->takeFromRecord($record, $take);
                $left -= $take;

                if ($record->is_legacy || $slice === null) {
                    $unknown += $take;
                } else {
                    $restored += $take;
                    $this->restoreUsage($slice, 'unpacking', $option, $on);
                }
            }

            $this->stock->moveOption($option->id, -$qty, 'unpacked', null, $on, $note);

            return ['restored_qty' => round($restored, 3), 'unknown_qty' => round($unknown, 3)];
        });
    }

    /**
     * Hand correction of part or kit stock (damage, count fix), with a reason.
     *
     * @param  'components'|'options'  $kind
     */
    public function adjust(string $kind, int $id, float $qty, string $reason, ?string $note = null): void
    {
        if (! in_array($reason, self::ADJUST_REASONS, true)) {
            throw new InvalidArgumentException("Unknown reason [{$reason}].");
        }

        DB::transaction(fn () => $this->stock->moveItem($kind, $id, $qty, $reason, null, null, null, $note));
    }

    /**
     * Plan the stock a quantity of parts uses.
     *
     * @param  list<array{component_id?: int|null, material_id?: int|null, qty: float|int|string}>  $parts
     * @return array{usage: array<string, mixed>, contents: list<array<string, mixed>>, cost: float}
     */
    public function plan(array $parts, float $qty): array
    {
        $bag = StockService::bag();
        $prededucted = [];
        $pool = [];

        foreach ($parts as $part) {
            $need = (float) $part['qty'] * $qty;

            if (! empty($part['component_id'])) {
                $component = Component::query()->lockForUpdate()->with('stages.lines.material')->findOrFail($part['component_id']);
                $materialQty = $need;

                if ($component->keeps_stock) {
                    $bag = StockService::mergeBags($bag, ['components' => [$component->id => $need]]);
                    $available = $pool[$component->id] ?? min($component->prededucted_stock, max($component->stock, 0));
                    $use = min($need, $available);
                    $pool[$component->id] = $available - $use;
                    $prededucted[$component->id] = ($prededucted[$component->id] ?? 0) + $use;
                    $materialQty = $need - $use;
                }

                if ($materialQty > 0) {
                    $bag = StockService::mergeBags($bag, ['materials' => $this->costing->componentUsage($component, $materialQty)]);
                }
            } else {
                $material = Material::query()->findOrFail($part['material_id']);

                if ($material->deductsStock()) {
                    $bag = StockService::mergeBags($bag, ['materials' => [$material->id => $need]]);
                }
            }
        }

        $snapshot = $this->costing->snapshot($parts, $qty);

        return [
            'usage' => $bag + ['prededucted' => array_map(fn ($v) => round($v, 3), array_filter($prededucted))],
            'contents' => $snapshot['lines'],
            'cost' => $snapshot['cost'],
        ];
    }

    /**
     * @param  array<string, mixed>  $usage
     */
    public function deductUsage(array $usage, string $reason, ?Model $ref = null, ?Carbon $on = null): void
    {
        $this->stock->deduct($usage, $reason, $ref, $on);

        foreach ($usage['prededucted'] ?? [] as $componentId => $qty) {
            Component::query()->whereKey($componentId)->decrement('prededucted_stock', $qty);
        }
    }

    /**
     * @param  array<string, mixed>  $usage
     */
    public function restoreUsage(array $usage, string $reason, ?Model $ref = null, ?Carbon $on = null): void
    {
        $this->stock->restore($usage, $reason, $ref, $on);

        foreach ($usage['prededucted'] ?? [] as $componentId => $qty) {
            Component::query()->whereKey($componentId)->increment('prededucted_stock', $qty);
        }
    }

    /**
     * Sell from ready-packed kits first (oldest packing first), saving which records were used.
     * Locks the option and its records; call inside a transaction.
     *
     * @return array{ready: float, allocations: list<array<string, mixed>>}
     */
    public function allocateReady(ProductOption $option, float $qty): array
    {
        $option = ProductOption::query()->lockForUpdate()->findOrFail($option->id);
        $this->reconcile($option);
        $ready = min(max($option->stock, 0), $qty);
        $allocations = [];
        $left = $ready;

        $records = PackRecord::query()->where('product_option_id', $option->id)->where('qty_remaining', '>', 0)
            ->orderBy('packed_on')->orderBy('id')->lockForUpdate()->get();

        foreach ($records as $record) {
            if ($left <= 1e-9) {
                break;
            }

            $take = min($left, $record->qty_remaining);
            $allocations[] = ['pack_record_id' => $record->id, 'qty' => round($take, 3), 'usage' => $this->takeFromRecord($record, $take)];
            $left -= $take;
        }

        return ['ready' => round($ready, 3), 'allocations' => $allocations];
    }

    /**
     * Put kits from a reversed sale back on their packing records.
     *
     * @param  list<array<string, mixed>>  $allocations
     */
    public function returnAllocations(array $allocations): void
    {
        foreach ($allocations as $allocation) {
            $record = PackRecord::query()->lockForUpdate()->find($allocation['pack_record_id']);

            if (! $record) {
                continue;
            }

            $record->qty_remaining = round($record->qty_remaining + $allocation['qty'], 3);

            if (! empty($allocation['usage'])) {
                $record->usage_remaining = $this->mergeUsages($record->usage_remaining ?? [], $allocation['usage']);
            }

            $record->save();
        }
    }

    /**
     * What a kit option takes out of stock right now, as plan parts. Figure and pot lines of a
     * kit that picks its figure and paint sets are left out: the picks take that stock.
     *
     * @return list<array{component_id: int|null, material_id: int|null, qty: float}>
     */
    public function optionParts(ProductOption $option): array
    {
        return $option->parts()->with('component', 'material')->get()
            ->reject(fn (OptionPart $part) => KitCounts::takenByPicks($option, $part))
            ->map(fn (OptionPart $part) => ['component_id' => $part->component_id, 'material_id' => $part->material_id, 'qty' => $part->qty])->values()->all();
    }

    /**
     * How many more kits the parts in stock can make, and which part runs out first.
     * Parts are shared, so this assumes you make only this option.
     *
     * @return array{can_make: int|null, limit: string|null}
     */
    public function canMakeOption(ProductOption $option): array
    {
        return $this->canMake($this->costing->optionStockUsage($option, 1));
    }

    /**
     * How many more of a part the materials in stock can make (e.g. paint pots from paint).
     *
     * @return array{can_make: int|null, limit: string|null}
     */
    public function canMakeComponent(Component $component): array
    {
        return $this->canMake(StockService::bag($this->costing->componentUsage($component, 1)));
    }

    /**
     * Make ready-kit stock match its packing records. Stock added or removed by hand has no
     * record: extra stock gets a legacy (contents unknown) record, missing stock is taken off
     * the newest records without restoring anything.
     */
    public function reconcile(ProductOption $option): void
    {
        $records = PackRecord::query()->where('product_option_id', $option->id)->where('qty_remaining', '>', 0)
            ->orderByDesc('packed_on')->orderByDesc('id')->lockForUpdate()->get();
        $diff = round($option->stock - $records->sum('qty_remaining'), 3);

        if ($diff > 0.0005) {
            PackRecord::query()->create([
                'product_option_id' => $option->id,
                'packed_on' => now()->toDateString(),
                'qty' => $diff,
                'qty_remaining' => $diff,
                'is_legacy' => true,
                'note' => 'Stock added without a packing record (count correction). Contents unknown.',
            ]);
        } elseif ($diff < -0.0005) {
            $excess = -$diff;

            foreach ($records as $record) {
                if ($excess <= 1e-9) {
                    break;
                }

                $take = min($excess, $record->qty_remaining);
                $this->takeFromRecord($record, $take);
                $excess -= $take;
            }
        }
    }

    /**
     * Remove $take kits from a record and return the share of saved usage that goes with them.
     *
     * @return array<string, mixed>|null
     */
    private function takeFromRecord(PackRecord $record, float $take): ?array
    {
        $fraction = $record->qty_remaining > 0 ? min(1, $take / $record->qty_remaining) : 0;
        $slice = null;

        if (! $record->is_legacy && $record->usage_remaining) {
            $slice = $this->scaleUsage($record->usage_remaining, $fraction);
            $record->usage_remaining = $this->scaleUsage($record->usage_remaining, 1 - $fraction);
        }

        $record->qty_remaining = round(max(0, $record->qty_remaining - $take), 3);
        $record->save();

        return $slice;
    }

    /**
     * @param  array<string, mixed>  $usage
     * @return array<string, mixed>
     */
    private function scaleUsage(array $usage, float $factor): array
    {
        $scaled = [];

        foreach ($usage as $kind => $items) {
            foreach ((array) $items as $id => $qty) {
                $value = round($qty * $factor, 3);

                if ($value != 0) {
                    $scaled[$kind][$id] = $value;
                }
            }
        }

        foreach (StockService::KINDS as $kind) {
            $scaled[$kind] ??= [];
        }

        $scaled['prededucted'] ??= [];

        return $scaled;
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     * @return array<string, mixed>
     */
    public function mergeUsages(array $a, array $b): array
    {
        $sum = StockService::mergeBags($a, $b);
        $sum['prededucted'] = [];

        foreach ([$a['prededucted'] ?? [], $b['prededucted'] ?? []] as $map) {
            foreach ($map as $id => $qty) {
                $sum['prededucted'][$id] = round(($sum['prededucted'][$id] ?? 0) + $qty, 3);
            }
        }

        return $sum;
    }

    /**
     * How many times a stock bag (what one unit takes) fits into what is in stock.
     *
     * @param  array{materials: array<int, float>, components: array<int, float>, options: array<int, float>}  $perUnit
     * @return array{can_make: int|null, limit: string|null}
     */
    public function canMakeBag(array $perUnit): array
    {
        return $this->canMake($perUnit);
    }

    /**
     * @param  array{materials: array<int, float>, components: array<int, float>, options: array<int, float>}  $perUnit
     * @return array{can_make: int|null, limit: string|null}
     */
    private function canMake(array $perUnit): array
    {
        $best = null;
        $limit = null;
        $items = [
            ...Material::query()->whereIn('id', array_keys($perUnit['materials']))->get()->map(fn ($m) => [$m->name, $m->stock, $perUnit['materials'][$m->id]]),
            ...Component::query()->whereIn('id', array_keys($perUnit['components']))->get()->map(fn ($c) => ["{$c->code} {$c->name}", $c->stock, $perUnit['components'][$c->id]]),
        ];

        foreach ($items as [$name, $stock, $need]) {
            if ($need <= 0) {
                continue;
            }

            $possible = (int) floor(max(0, $stock) / $need + 1e-9);

            if ($best === null || $possible < $best) {
                $best = $possible;
                $limit = $name;
            }
        }

        return ['can_make' => $best, 'limit' => $limit];
    }

    private function ensureKeepsStock(Component $component): void
    {
        if (! $component->keeps_stock) {
            throw new InvalidArgumentException("{$component->code} does not keep its own stock.");
        }
    }
}
