<?php

namespace App\Services;

use App\Models\Component;
use App\Models\ComponentStage;
use App\Models\Material;
use App\Models\OptionPart;
use App\Models\ProductOption;
use App\Models\Setting;

/**
 * Computes component and option costs from current material prices. Nothing is cached.
 */
class Costing
{
    public const MARGINS = [0.2, 0.3, 0.4, 0.5, 0.6];

    public function batchCost(Component $component): float
    {
        $component->loadMissing('stages.lines.material');

        return $component->stages->sum(fn ($stage) => $this->stageCost($stage));
    }

    public function stageCost(ComponentStage $stage): float
    {
        $stage->loadMissing('lines.material');

        return (float) $stage->lines->sum(fn ($line) => $line->qty * ($line->material?->unit_cost ?? 0));
    }

    public function componentUnitCost(Component $component): float
    {
        return CostMath::unitCost($this->batchCost($component), $component->yield_qty, $component->wastage_pct);
    }

    /**
     * Stage-by-stage breakdown with running total, per batch.
     *
     * @return array{stages: list<array{id: int, name: string, cost: float, running: float}>, batch: float, wastage: float, unit: float}
     */
    public function componentBreakdown(Component $component): array
    {
        $component->loadMissing('stages.lines.material');
        $running = 0.0;
        $stages = [];

        foreach ($component->stages as $stage) {
            $cost = $this->stageCost($stage);
            $running += $cost;
            $stages[] = ['id' => $stage->id, 'name' => $stage->name, 'cost' => round($cost, 4), 'running' => round($running, 4)];
        }

        $wastage = $running * $component->wastage_pct / 100;

        return [
            'stages' => $stages,
            'batch' => round($running, 4),
            'wastage' => round($wastage, 4),
            'unit' => round($this->componentUnitCost($component), 4),
        ];
    }

    public function partUnitCost(OptionPart $part): float
    {
        if ($part->component_id) {
            return $this->componentUnitCost($part->component);
        }

        return $part->material?->unit_cost ?? 0.0;
    }

    public function optionCost(ProductOption $option): float
    {
        $option->loadMissing('parts.material', 'parts.component.stages.lines.material');

        return (float) $option->parts->sum(fn (OptionPart $part) => $part->qty * $this->partUnitCost($part));
    }

    public function courierExtraCost(ProductOption $option): float
    {
        $option->loadMissing('courierPacking.stages.lines.material');
        $packing = $option->courierPacking ? $this->componentUnitCost($option->courierPacking) : 0.0;

        return $packing + $option->courier_fee_estimate;
    }

    public function optionCostWithCourier(ProductOption $option): float
    {
        return $this->optionCost($option) + $this->courierExtraCost($option);
    }

    /**
     * Stock materials consumed by making $qty units of a component (planned wastage included).
     *
     * @return array<int, float> material_id => qty
     */
    public function componentUsage(Component $component, float $qty = 1): array
    {
        $component->loadMissing('stages.lines.material');
        $usage = [];

        if ($component->yield_qty <= 0) {
            return $usage;
        }

        $factor = $qty / $component->yield_qty * (1 + $component->wastage_pct / 100);

        foreach ($component->stages as $stage) {
            foreach ($stage->lines as $line) {
                if ($line->material && ! $line->material->isOverhead()) {
                    $usage[$line->material_id] = ($usage[$line->material_id] ?? 0) + $line->qty * $factor;
                }
            }
        }

        return $usage;
    }

    /**
     * @return array<int, float> material_id => qty
     */
    public function optionUsage(ProductOption $option, float $qty = 1): array
    {
        $option->loadMissing('parts.material', 'parts.component.stages.lines.material');
        $usage = [];

        foreach ($option->parts as $part) {
            if ($part->component_id) {
                $usage = self::mergeUsage($usage, $this->componentUsage($part->component, $part->qty * $qty));
            } elseif ($part->material && ! $part->material->isOverhead()) {
                $usage = self::mergeUsage($usage, [$part->material_id => $part->qty * $qty]);
            }
        }

        return $usage;
    }

    /**
     * What using $qty of a component takes out of stock: the component itself when it keeps
     * its own stock (made in advance), otherwise its recipe materials.
     *
     * @return array{materials: array<int, float>, components: array<int, float>, options: array<int, float>}
     */
    public function componentStockUsage(Component $component, float $qty = 1): array
    {
        if ($component->keeps_stock) {
            return StockService::bag(['components' => [$component->id => $qty]]);
        }

        return StockService::bag($this->componentUsage($component, $qty));
    }

    /**
     * What packing $qty of a kit option takes out of stock.
     *
     * @return array{materials: array<int, float>, components: array<int, float>, options: array<int, float>}
     */
    public function optionStockUsage(ProductOption $option, float $qty = 1): array
    {
        $option->loadMissing('parts.material', 'parts.component.stages.lines.material');
        $bag = StockService::bag();

        foreach ($option->parts as $part) {
            if ($part->component) {
                $bag = StockService::mergeBags($bag, $this->componentStockUsage($part->component, $part->qty * $qty));
            } elseif ($part->material && ! $part->material->isOverhead()) {
                $bag = StockService::mergeBags($bag, [$part->material_id => $part->qty * $qty]);
            }
        }

        return $bag;
    }

    /**
     * @param  array<int, float>  $a
     * @param  array<int, float>  $b
     * @return array<int, float>
     */
    public static function mergeUsage(array $a, array $b): array
    {
        foreach ($b as $materialId => $qty) {
            $a[$materialId] = ($a[$materialId] ?? 0) + $qty;
        }

        return $a;
    }

    /**
     * Suggested prices for each target margin, without and with courier.
     *
     * @return array{cost: float, cost_courier: float, min_margin: float, rows: list<array<string, float>>}
     */
    public function suggest(ProductOption $option): array
    {
        $cost = $this->optionCost($option);
        $costCourier = $this->optionCostWithCourier($option);
        $step = max(1, (int) Setting::value('price_step'));
        $rows = [];

        foreach (self::MARGINS as $margin) {
            $price = CostMath::priceForMargin($cost, $margin, $step);
            $priceCourier = CostMath::priceForMargin($costCourier, $margin, $step);
            $rows[] = [
                'target' => $margin,
                'price' => $price,
                'profit' => CostMath::profit($price, $cost)['profit'],
                'markup' => CostMath::profit($price, $cost)['markup'],
                'price_courier' => $priceCourier,
                'profit_courier' => CostMath::profit($priceCourier, $costCourier)['profit'],
                'markup_courier' => CostMath::profit($priceCourier, $costCourier)['markup'],
            ];
        }

        return [
            'cost' => round($cost, 4),
            'cost_courier' => round($costCourier, 4),
            'min_margin' => (float) Setting::value('min_margin_pct') / 100,
            'rows' => $rows,
        ];
    }

    /**
     * Cost of a usage map at current material prices.
     *
     * @param  array<int, float>  $usage
     */
    public function usageCost(array $usage): float
    {
        $costs = Material::query()->whereIn('id', array_keys($usage))->pluck('unit_cost', 'id');

        return (float) collect($usage)->sum(fn ($qty, $materialId) => $qty * (float) ($costs[$materialId] ?? 0));
    }
}
