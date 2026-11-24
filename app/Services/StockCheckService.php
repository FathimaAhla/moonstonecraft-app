<?php

namespace App\Services;

use App\Models\Component;
use App\Models\Material;
use App\Models\ProductOption;
use App\Models\StockCheck;
use App\Models\StockCheckLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Stock counting: start (freeze system quantities), save counts, complete (write missing / found).
 */
class StockCheckService
{
    /** Line item_type => stock ledger kind. */
    public const ITEM_KINDS = ['material' => 'materials', 'component' => 'components', 'option' => 'options'];

    /** Reasons you can give a difference; empty means missing (short) or found (extra). */
    public const LINE_REASONS = ['missing', 'found', 'damaged'];

    public function __construct(private StockService $stock, private Costing $costing) {}

    public function start(string $scope, string $checkedOn, ?string $notes = null): StockCheck
    {
        if (! in_array($scope, StockCheck::SCOPES, true)) {
            throw new InvalidArgumentException("Unknown scope [{$scope}].");
        }

        return DB::transaction(function () use ($scope, $checkedOn, $notes) {
            $check = StockCheck::query()->create(['checked_on' => $checkedOn, 'scope' => $scope, 'notes' => $notes]);
            $check->lines()->createMany($this->snapshot($scope));

            return $check;
        });
    }

    /**
     * @param  array<int|string, array{counted_qty?: float|string|null, reason?: string|null, note?: string|null}>  $counts  line id => values
     */
    public function saveCounts(StockCheck $check, array $counts): void
    {
        $this->ensureDraft($check);

        DB::transaction(function () use ($check, $counts) {
            foreach ($check->lines()->whereIn('id', array_keys($counts))->get() as $line) {
                $values = $counts[$line->id];
                $counted = $values['counted_qty'] ?? null;

                $line->update([
                    'counted_qty' => $counted === null || $counted === '' ? null : (float) $counted,
                    'reason' => filled($values['reason'] ?? null) ? $values['reason'] : null,
                    'note' => $values['note'] ?? null,
                ]);
            }
        });
    }

    /**
     * Write every counted difference to the ledger and lock the check. Movements made after the
     * check started are kept: only (counted - frozen) is applied to today's stock.
     */
    public function complete(StockCheck $check): void
    {
        $this->ensureDraft($check);

        DB::transaction(function () use ($check) {
            $on = Carbon::parse($check->checked_on);

            foreach ($check->lines as $line) {
                $difference = $line->difference();

                if (! $difference) {
                    continue;
                }

                $reason = $line->reason ?? ($difference < 0 ? 'missing' : 'found');
                $this->stock->moveItem(self::ITEM_KINDS[$line->item_type], $line->item_id, $difference, $reason, $check, $on, null, $line->note);
            }

            $check->update(['status' => 'completed', 'completed_at' => now()]);
        });
    }

    /**
     * @return array{lines: int, counted: int, missing_value: float, found_value: float}
     */
    public function summary(StockCheck $check): array
    {
        $lines = $check->lines;
        $value = fn (StockCheckLine $line) => ($line->difference() ?? 0) * $line->unit_cost;

        return [
            'lines' => $lines->count(),
            'counted' => $lines->whereNotNull('counted_qty')->count(),
            'missing_value' => round(-$lines->filter(fn ($l) => $l->difference() < 0)->sum($value), 2),
            'found_value' => round($lines->filter(fn ($l) => $l->difference() > 0)->sum($value), 2),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function snapshot(string $scope): array
    {
        $lines = [];

        if (in_array($scope, ['all', 'materials'], true)) {
            foreach (Material::query()->where('tracks_stock', true)->orderBy('name')->get() as $material) {
                $lines[] = ['item_type' => 'material', 'item_id' => $material->id, 'name' => $material->name, 'unit' => $material->unit, 'system_qty' => $material->stock, 'unit_cost' => $material->unit_cost];
            }
        }

        if (in_array($scope, ['all', 'components'], true)) {
            foreach (Component::query()->with('stages.lines.material')->where('keeps_stock', true)->orderBy('code')->get() as $component) {
                $lines[] = ['item_type' => 'component', 'item_id' => $component->id, 'name' => "{$component->code} {$component->name}", 'unit' => 'pc', 'system_qty' => $component->stock, 'unit_cost' => round($this->costing->componentUnitCost($component), 4)];
            }
        }

        if (in_array($scope, ['all', 'kits'], true)) {
            $options = ProductOption::query()->with('product', 'parts.material', 'parts.component.stages.lines.material')
                ->where(fn ($q) => $q->where('active', true)->orWhere('stock', '!=', 0))->orderBy('code')->get();

            foreach ($options as $option) {
                $lines[] = ['item_type' => 'option', 'item_id' => $option->id, 'name' => "{$option->code} {$option->product?->name} - {$option->name}", 'unit' => 'kit', 'system_qty' => $option->stock, 'unit_cost' => round($this->costing->optionCost($option), 4)];
            }
        }

        return $lines;
    }

    private function ensureDraft(StockCheck $check): void
    {
        if (! $check->isDraft()) {
            throw new InvalidArgumentException('This stock check is already completed.');
        }
    }
}
