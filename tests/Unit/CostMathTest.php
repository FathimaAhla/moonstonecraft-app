<?php

use App\Services\CostMath;

test('unit cost divides by yield and adds wastage', function () {
    expect(CostMath::unitCost(22.0, 1, 6.45))->toEqualWithDelta(23.419, 0.001)
        ->and(CostMath::unitCost(100, 0, 10))->toBe(0.0);
});

test('price for margin rounds up to the step and is never below cost', function () {
    expect(CostMath::priceForMargin(100, 0.4))->toBe(170.0)
        ->and(CostMath::priceForMargin(100, 0.5))->toBe(200.0)
        ->and(CostMath::priceForMargin(123.45, 0.2, 10))->toBeGreaterThanOrEqual(123.45)
        ->and(CostMath::priceForMargin(100, 1))->toBe(0.0);
});

test('profit margin and markup', function () {
    $result = CostMath::profit(200, 150);

    expect($result['profit'])->toBe(50.0)
        ->and($result['margin'])->toBe(0.25)
        ->and($result['markup'])->toEqualWithDelta(0.3333, 0.0001);
});

test('weighted average cost', function () {
    expect(CostMath::weightedAverage(1000, 0.30, 1000, 0.40))->toEqualWithDelta(0.35, 0.00001)
        ->and(CostMath::weightedAverage(0, 0.30, 500, 0.40))->toBe(0.40);
});

test('allocation by value sums back to the extra', function () {
    $alloc = CostMath::allocateByValue([1600, 800], 300);

    expect($alloc[0])->toEqualWithDelta(200, 0.0001)
        ->and($alloc[1])->toEqualWithDelta(100, 0.0001)
        ->and(array_sum($alloc))->toEqualWithDelta(300, 0.0001);
});
