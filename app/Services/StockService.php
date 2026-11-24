<?php

namespace App\Services;

use App\Models\Component;
use App\Models\Material;
use App\Models\ProductOption;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Every stock change goes through here so the ledger always matches the stock columns.
 *
 * Stock lives at three levels: materials (bought), components that keep stock (figures,
 * paint pots you make) and kit options (packed, ready to sell). A "bag" describes a
 * mixed usage: ['materials' => [id => qty], 'components' => [...], 'options' => [...]].
 * A plain [material_id => qty] map (the older format) is still accepted everywhere.
 */
class StockService
{
    public const KINDS = ['materials', 'components', 'options'];

    /**
     * Move material stock in (+) or out (-) and write a ledger row. Call inside a transaction.
     */
    public function move(int $materialId, float $qty, string $reason, ?Model $ref = null, ?Carbon $on = null, ?float $newUnitCost = null, ?string $note = null): StockMovement
    {
        return $this->moveItem('materials', $materialId, $qty, $reason, $ref, $on, $newUnitCost, $note);
    }

    public function moveComponent(int $componentId, float $qty, string $reason, ?Model $ref = null, ?Carbon $on = null, ?string $note = null): StockMovement
    {
        return $this->moveItem('components', $componentId, $qty, $reason, $ref, $on, null, $note);
    }

    public function moveOption(int $optionId, float $qty, string $reason, ?Model $ref = null, ?Carbon $on = null, ?string $note = null): StockMovement
    {
        return $this->moveItem('options', $optionId, $qty, $reason, $ref, $on, null, $note);
    }

    /**
     * @param  'materials'|'components'|'options'  $kind
     */
    public function moveItem(string $kind, int $id, float $qty, string $reason, ?Model $ref = null, ?Carbon $on = null, ?float $newUnitCost = null, ?string $note = null): StockMovement
    {
        $item = match ($kind) {
            'materials' => Material::query()->lockForUpdate()->findOrFail($id),
            'components' => Component::query()->lockForUpdate()->findOrFail($id),
            'options' => ProductOption::query()->lockForUpdate()->findOrFail($id),
        };

        if ($newUnitCost !== null && $item instanceof Material) {
            $item->purchase_unit_cost = round($newUnitCost, 4);
        }

        $item->stock = round($item->stock + $qty, 3);
        $item->save();

        return StockMovement::query()->create([
            'material_id' => $kind === 'materials' ? $id : null,
            'component_id' => $kind === 'components' ? $id : null,
            'product_option_id' => $kind === 'options' ? $id : null,
            'moved_on' => ($on ?? now())->toDateString(),
            'qty' => round($qty, 3),
            'reason' => $reason,
            'ref_type' => $ref?->getMorphClass(),
            'ref_id' => $ref?->getKey(),
            'unit_cost' => $this->unitCost($item),
            'note' => $note,
        ]);
    }

    /**
     * Take a usage bag (or legacy material map) out of stock.
     *
     * @param  array<int|string, mixed>  $usage
     */
    public function deduct(array $usage, string $reason, ?Model $ref = null, ?Carbon $on = null): void
    {
        $this->apply($usage, -1, $reason, $ref, $on);
    }

    /**
     * Put a usage bag (or legacy material map) back into stock (reversal).
     *
     * @param  array<int|string, mixed>  $usage
     */
    public function restore(array $usage, string $reason, ?Model $ref = null, ?Carbon $on = null): void
    {
        $this->apply($usage, 1, $reason, $ref, $on);
    }

    /**
     * @param  array<int|string, mixed>  $usage
     * @return array{materials: array<int, float>, components: array<int, float>, options: array<int, float>}
     */
    public static function bag(array $usage = []): array
    {
        $isBag = array_intersect(array_keys($usage), self::KINDS) !== [];

        if (! $isBag) {
            $usage = ['materials' => $usage];
        }

        $bag = [];

        foreach (self::KINDS as $kind) {
            $bag[$kind] = [];

            foreach ($usage[$kind] ?? [] as $id => $qty) {
                $bag[$kind][(int) $id] = (float) $qty;
            }
        }

        return $bag;
    }

    /**
     * @param  array<int|string, mixed>  $a
     * @param  array<int|string, mixed>  $b
     * @return array{materials: array<int, float>, components: array<int, float>, options: array<int, float>}
     */
    public static function mergeBags(array $a, array $b): array
    {
        $a = self::bag($a);

        foreach (self::bag($b) as $kind => $items) {
            foreach ($items as $id => $qty) {
                $a[$kind][$id] = ($a[$kind][$id] ?? 0) + $qty;
            }
        }

        return $a;
    }

    /**
     * @param  array<int|string, mixed>  $usage
     */
    private function apply(array $usage, int $sign, string $reason, ?Model $ref, ?Carbon $on): void
    {
        foreach (self::bag($usage) as $kind => $items) {
            foreach ($items as $id => $qty) {
                if ($qty != 0) {
                    $this->moveItem($kind, $id, $sign * $qty, $reason, $ref, $on);
                }
            }
        }
    }

    private function unitCost(Material|Component|ProductOption $item): float
    {
        if ($item instanceof Material) {
            return $item->unit_cost;
        }

        $costing = app(Costing::class);

        return round($item instanceof Component ? $costing->componentUnitCost($item) : $costing->optionCost($item), 4);
    }
}
