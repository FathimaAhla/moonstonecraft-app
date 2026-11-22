<?php

namespace App\Services;

/**
 * Pure cost and pricing formulas, with no database access.
 */
final class CostMath
{
    public static function unitCost(float $batchTotal, float $yield, float $wastePct): float
    {
        return $yield > 0 ? $batchTotal / $yield * (1 + $wastePct / 100) : 0.0;
    }

    public static function priceForMargin(float $cost, float $margin, int $step = 10): float
    {
        if ($margin >= 1 || $step <= 0) {
            return 0.0;
        }

        return ceil(round($cost / (1 - $margin), 6) / $step) * $step;
    }

    /**
     * @return array{profit: float, margin: float, markup: float}
     */
    public static function profit(float $price, float $cost): array
    {
        $profit = $price - $cost;

        return [
            'profit' => round($profit, 2),
            'margin' => $price != 0.0 ? $profit / $price : 0.0,
            'markup' => $cost != 0.0 ? $profit / $cost : 0.0,
        ];
    }

    public static function weightedAverage(float $stock, float $unitCost, float $qty, float $landedUnitCost): float
    {
        if ($stock <= 0) {
            return $landedUnitCost;
        }

        return ($stock * $unitCost + $qty * $landedUnitCost) / ($stock + $qty);
    }

    /**
     * Spread extra cost across lines in proportion to each line's value.
     *
     * @param  array<int|string, float>  $lineCosts
     * @return array<int|string, float>
     */
    public static function allocateByValue(array $lineCosts, float $extra): array
    {
        $total = array_sum($lineCosts);

        return array_map(fn (float $lineCost) => $total > 0 ? $extra * $lineCost / $total : 0.0, $lineCosts);
    }
}
