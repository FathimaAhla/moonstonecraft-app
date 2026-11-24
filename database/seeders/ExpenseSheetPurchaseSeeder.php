<?php

namespace Database\Seeders;

use App\Models\Expense;
use App\Models\Material;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Services\CostMath;
use App\Services\PurchaseService;
use Illuminate\Database\Seeder;

/**
 * The real purchases from the "Business Expense Sheet – Craft Product Purchase" (Rs 49,100 in all).
 *
 * Each supplier block becomes a purchase, so stock and landed costs update as if it were entered
 * by hand. Supplier discounts lower the line costs, shipping is landed cost, a shopping trip's
 * travel is shared across that day's purchases by value, and refreshments become expenses.
 *
 * Safe to run again: a purchase whose note already exists is skipped.
 */
class ExpenseSheetPurchaseSeeder extends Seeder
{
    private const NOTE_PREFIX = 'Business expense sheet: ';

    /**
     * Material name => [stock unit, purchase unit, stock units per purchase unit, tracks stock].
     * Existing materials keep their own settings; these only apply when the material is new.
     *
     * @var array<string, array{0: string, 1: string|null, 2: float, 3: bool}>
     */
    private const MATERIALS = [
        'Plaster of Paris' => ['g', 'kg', 1000, true],
        'Gypsum powder' => ['g', 'kg', 1000, true],
        'Thailand POP' => ['bag', null, 1, true],
        'Oman POP' => ['bag', null, 1, true],
        'Acrylic paint' => ['ml', 'bottle', 75, true],
        'Red paint' => ['pc', null, 1, true],
        'Green paint' => ['pc', null, 1, true],
        'Blue paint' => ['pc', null, 1, true],
        'Brown paint' => ['pc', null, 1, true],
        'Empty paint pot' => ['pc', 'strip', 6, true],
        'Paint brush' => ['pc', null, 1, true],
        'Chemifix glue' => ['pc', null, 1, true],
        'Isopropyl alcohol' => ['pc', null, 1, true],
        'Background cover' => ['pc', null, 1, true],
        'Sticker' => ['pc', 'sheet', 35, true],
        'Sticker pack' => ['pc', null, 1, true],
        'Fragile sticker' => ['pc', 'roll', 500, true],
        'Cardboard box' => ['pc', null, 1, true],
        '400 GSM board' => ['pc', null, 1, true],
        '80 GSM paper' => ['pc', null, 1, true],
        '50 GSM paper' => ['pc', null, 1, true],
        'Courier bag' => ['pc', null, 1, true],
        'OPP self-sealing bag' => ['pc', null, 1, true],
        'OPP bag 6 x 9' => ['pc', null, 1, true],
        'Polythene bag (small)' => ['pc', null, 1, true],
        'BOPP bag 5 x 8' => ['pc', null, 1, true],
        'BOPP bag 7 x 10' => ['pc', null, 1, true],
        'BOPP bag 10 x 12' => ['pc', null, 1, true],
        'Tape roll (1 inch x 100 m)' => ['roll', null, 1, true],
        'Bubble wrap roll (1 ft x 50 m)' => ['roll', null, 1, true],
        'Hand gloves' => ['pc', null, 1, false],
        'Brush (large)' => ['pc', null, 1, false],
        'Sand paper' => ['pc', null, 1, false],
        'Bubble blaster' => ['pc', null, 1, false],
        'Vehicle mold' => ['pc', null, 1, false],
        'Alphabet mold' => ['pc', null, 1, false],
        'Ocean mold' => ['pc', null, 1, false],
        'Flower mold' => ['pc', null, 1, false],
        'Fruit mold' => ['pc', null, 1, false],
        'Butter bear mold' => ['pc', null, 1, false],
        '3 cup mold' => ['pc', null, 1, false],
        '3D cup mold' => ['pc', null, 1, false],
        'Astronaut mold' => ['pc', null, 1, false],
        'Lotus mold' => ['pc', null, 1, false],
        'Tray & pot' => ['pc', null, 1, false],
    ];

    public function run(): void
    {
        foreach ($this->trips() as $trip) {
            $this->recordTrip($trip);
        }

        Expense::query()->firstOrCreate(
            ['category' => 'Website', 'description' => 'Domain'],
            ['spent_on' => '2026-09-07', 'amount' => 4100],
        );
    }

    /**
     * Purchases grouped by shopping trip. `travel` and `refreshment` belong to the whole trip.
     *
     * @return list<array{date: string, travel: float, refreshment: float, purchases: list<array{label: string, supplier: string|null, shipping?: float, discount?: float, lines: list<array{0: string, 1: float, 2: float}>}>}>
     */
    private function trips(): array
    {
        return [
            [
                'date' => '2026-09-07',
                'travel' => 1120, // Katowita → Nittambuwa 4 × 70, Nittambuwa → Colombo 4 × 210
                'refreshment' => 440,
                'purchases' => [
                    ['label' => 'Master Colors Stores – Molds & Materials – 07/09/2026', 'supplier' => 'Master Colors Stores', 'discount' => 71.64, 'lines' => [
                        ['Gypsum powder', 2, 249.98],
                        ['Bubble blaster', 1, 450],
                        ['Plaster of Paris', 2, 666.66],
                        ['Vehicle mold', 1, 855],
                        ['Alphabet mold', 1, 1125],
                        ['Ocean mold', 1, 765],
                        ['Flower mold', 1, 765],
                        ['Fruit mold', 1, 765],
                        ['Butter bear mold', 1, 855],
                        ['3 cup mold', 1, 765],
                        ['3D cup mold', 1, 990],
                    ]],
                    ['label' => 'Master Craft Products – 07/09/2026', 'supplier' => 'Master Craft Products', 'lines' => [
                        ['Paint brush', 50, 1500],
                        ['Empty paint pot', 25, 1750],
                        ['Acrylic paint', 8, 2600],
                        ['Chemifix glue', 1, 440],
                    ]],
                ],
            ],
            [
                'date' => '2026-09-07',
                'travel' => 0,
                'refreshment' => 0,
                'purchases' => [
                    ['label' => 'Other expenses', 'supplier' => null, 'lines' => [
                        ['OPP self-sealing bag', 15, 105],
                        ['Polythene bag (small)', 10, 25],
                        ['Plaster of Paris', 15, 1800],
                        ['Background cover', 10, 350],
                        ['Sticker pack', 1, 120],
                        ['Hand gloves', 4, 100],
                        ['Brush (large)', 2, 100],
                        ['Sand paper', 2, 300],
                    ]],
                    ['label' => 'Daraz', 'supplier' => 'Daraz', 'shipping' => 440, 'discount' => 601, 'lines' => [
                        ['Tray & pot', 1, 1839],
                        ['Astronaut mold', 1, 1101],
                    ]],
                    ['label' => 'Packit', 'supplier' => 'Packit', 'shipping' => 450, 'discount' => 80, 'lines' => [
                        ['BOPP bag 5 x 8', 200, 740],
                        ['BOPP bag 7 x 10', 200, 1000],
                        ['BOPP bag 10 x 12', 100, 830],
                    ]],
                    ['label' => 'DN Factory', 'supplier' => 'DN Factory', 'shipping' => 625, 'discount' => 74, 'lines' => [
                        ['Courier bag', 100, 1550],
                        ['OPP bag 6 x 9', 100, 480],
                        ['Tape roll (1 inch x 100 m)', 1, 160],
                        ['Bubble wrap roll (1 ft x 50 m)', 1, 1525],
                    ]],
                    ['label' => 'Ama Sticker', 'supplier' => 'Ama Sticker', 'shipping' => 200, 'lines' => [
                        ['Sticker', 3, 600],
                    ]],
                ],
            ],
            [
                'date' => '2026-10-06',
                'travel' => 1100, // bus 950, wheel Kahatowita → home 150
                'refreshment' => 1340,
                'purchases' => [
                    // Lorry 400 and wheel Bankshall Street → lorry 450 carried only the POP.
                    ['label' => 'Kannan & Co – POP – 06/10/2026', 'supplier' => 'Kannan & Co', 'shipping' => 850, 'lines' => [
                        ['Thailand POP', 1, 3500],
                        ['Oman POP', 1, 1700],
                    ]],
                    ['label' => 'Master Craft – Paint Colors – 06/10/2026', 'supplier' => 'Master Craft Products', 'lines' => [
                        ['Red paint', 1, 210],
                        ['Green paint', 1, 210],
                        ['Blue paint', 1, 210],
                        ['Brown paint', 1, 210],
                    ]],
                    ['label' => 'Shaan Traders – Boxes & Boards – 06/10/2026', 'supplier' => 'Shaan Traders', 'lines' => [
                        ['Cardboard box', 1, 120], // 9 1/2 x 6 1/2 x 2
                        ['Cardboard box', 1, 120], // 7 1/4 x 4 x 2 1/4
                        ['Cardboard box', 1, 120], // 8 x 4 x 3
                        ['Cardboard box', 1, 85], // 5 3/4 x 3 1/4 x 3 1/4
                        ['400 GSM board', 1, 200],
                        ['80 GSM paper', 1, 50],
                        ['50 GSM paper', 2, 60],
                    ]],
                    ['label' => 'Sri Balaji Chemical – 06/10/2026', 'supplier' => 'Sri Balaji Chemical', 'shipping' => 650, 'lines' => [
                        ['Isopropyl alcohol', 1, 1600],
                    ]],
                ],
            ],
            [
                'date' => '2026-10-06',
                'travel' => 0,
                'refreshment' => 0,
                'purchases' => [
                    ['label' => 'Temu order', 'supplier' => 'Temu', 'discount' => 83, 'lines' => [
                        ['Paint brush', 60, 1170],
                        ['Fragile sticker', 1, 1113],
                        ['Lotus mold', 1, 750],
                    ]],
                ],
            ],
        ];
    }

    /**
     * @param  array{date: string, travel: float, refreshment: float, purchases: list<array{label: string, supplier: string|null, shipping?: float, discount?: float, lines: list<array{0: string, 1: float, 2: float}>}>}  $trip
     */
    private function recordTrip(array $trip): void
    {
        $values = array_map(fn (array $purchase) => $this->paidForItems($purchase), $trip['purchases']);
        $travelShares = $this->splitExactly($values, $trip['travel']);

        foreach ($trip['purchases'] as $index => $purchase) {
            $note = self::NOTE_PREFIX.$purchase['label'];

            if (Purchase::query()->where('notes', $note)->exists()) {
                continue;
            }

            $lineCosts = $this->splitExactly(array_map(fn (array $line) => (float) $line[2], $purchase['lines']), $this->paidForItems($purchase));

            app(PurchaseService::class)->record([
                'purchased_on' => $trip['date'],
                'supplier_id' => $purchase['supplier'] ? Supplier::query()->firstOrCreate(['name' => $purchase['supplier']])->id : null,
                'transport_cost' => ($purchase['shipping'] ?? 0) + $travelShares[$index],
                'refreshment_cost' => $index === 0 ? $trip['refreshment'] : 0,
                'notes' => $note,
            ], array_map(fn (array $line, float $cost) => $this->purchaseLine($line[0], $line[1], $cost), $purchase['lines'], $lineCosts));
        }
    }

    /**
     * @return array{material_id: int, qty: float, line_cost: float, purchase_unit: string|null, conversion_factor: float}
     */
    private function purchaseLine(string $name, float $qty, float $cost): array
    {
        [$unit, $purchaseUnit, $perPurchaseUnit, $tracksStock] = self::MATERIALS[$name];

        $material = Material::query()->firstOrCreate(['name' => $name], [
            'unit' => $unit,
            'purchase_unit' => $purchaseUnit,
            'purchase_conversion' => $perPurchaseUnit,
            'tracks_stock' => $tracksStock,
            'unit_cost' => 0,
        ]);

        return ['material_id' => $material->id, 'qty' => $qty, 'line_cost' => $cost, 'purchase_unit' => $purchaseUnit, 'conversion_factor' => $perPurchaseUnit];
    }

    /**
     * What was paid for the items after the supplier's discount.
     *
     * @param  array{lines: list<array{0: string, 1: float, 2: float}>, discount?: float}  $purchase
     */
    private function paidForItems(array $purchase): float
    {
        return round(array_sum(array_column($purchase['lines'], 2)) - ($purchase['discount'] ?? 0), 2);
    }

    /**
     * Spread an amount across values by weight, rounded to cents, adding any rounding gap to the last share.
     *
     * @param  list<float>  $weights
     * @return list<float>
     */
    private function splitExactly(array $weights, float $amount): array
    {
        $shares = array_map(fn (float $share) => round($share, 2), CostMath::allocateByValue($weights, $amount));
        $shares[array_key_last($shares)] = round($amount - array_sum(array_slice($shares, 0, -1)), 2);

        return $shares;
    }
}
