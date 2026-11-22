<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Component;
use App\Models\Material;
use Illuminate\Database\Seeder;

/**
 * Materials and costed components for the DIY painting kits, taken from the
 * "Business Cost Estimate Sheet" (large figures and fruit figures).
 *
 * Safe to run again: materials are matched by name and components by code, their
 * costs and recipes are refreshed, and stock quantities are never touched.
 */
class PaintingComponentSeeder extends Seeder
{
    /**
     * Theme code => [name, figure size]. Large figures use the vehicle / astronaut / butterfly-bunny
     * sheet; small figures use the fruit sheet.
     *
     * @var array<string, array{0: string, 1: 'large'|'small'}>
     */
    public const THEMES = [
        'VEH' => ['Vehicle', 'large'],
        'AST' => ['Astronaut', 'large'],
        'CUT' => ['Cutie (butterfly / bunny)', 'large'],
        'ALP' => ['Alphabet', 'large'],
        'FRU' => ['Fruits', 'small'],
        'FLW' => ['Flower', 'small'],
        'OCN' => ['Ocean', 'small'],
    ];

    /**
     * Kit size => [product packing Rs, handling and other Rs], from the kit price list.
     *
     * @var array<string, array{0: float, 1: float}>
     */
    public const SIZES = [
        'Small' => [83, 20],
        'Medium' => [98, 20],
        'Large' => [113, 20],
        'XL' => [135, 30],
        'XXL' => [160, 40],
        'Jumbo' => [190, 55],
    ];

    /** Polythene + background cover + crinkle + sticker. */
    private const BASE_PACKING_COST = 56;

    /** @var array<string, Material> */
    private array $materials = [];

    public function run(): void
    {
        $this->seedMaterials();
        $category = Category::query()->where('code_prefix', 'PNT')->firstOrFail();

        foreach (self::THEMES as $code => [$name, $size]) {
            $this->component($category, "FIG-{$code}", "{$name} figure", $size === 'large' ? 1 : 4, true, $size === 'large'
                ? [
                    'Mixing' => ['Plaster of Paris' => 34, 'Water' => 10],
                    'Casting' => ['Release spray' => 0.25, 'Silicone mold wear' => 1, 'Making labor' => 5],
                    'Overheads' => ['Figure wastage allowance' => 1, 'Material transport' => 1, 'Equipment wear and electricity' => 1],
                ]
                : [
                    'Mixing' => ['Plaster of Paris' => 70, 'Water' => 0.25],
                    'Casting' => ['Silicone mold wear' => 1, 'Making labor' => 2.5],
                    'Overheads' => ['Figure wastage allowance' => 1, 'Material transport' => 1, 'Equipment wear and electricity' => 1],
                ]);
        }

        $this->component($category, 'PAINT-POT', 'Paint pot (2 ml filled)', 1, true, [
            'Filling' => ['Empty paint pot' => 1, 'Acrylic paint' => 2],
        ]);

        $this->component($category, 'KIT-ASM', 'Kit assembly', 1, false, [
            'Assembly' => ['Kit assembly labor' => 1, 'Kit other cost' => 1],
        ]);

        foreach (self::SIZES as $size => [$packing, $other]) {
            $this->component($category, 'PCK-'.strtoupper($size), "Kit packing - {$size}", 1, false, [
                'Packing' => ['Polythene' => 1, 'Background cover' => 1, 'Crinkle paper shred' => 1, 'Sticker' => 1, 'Packing allowance' => $packing - self::BASE_PACKING_COST],
                'Handling' => ['Handling and other' => $other],
            ]);
        }

        $this->component($category, 'PCK-BOX', 'Gift box packing', 1, false, [
            'Packing' => ['Cardboard box' => 1, 'Crinkle paper shred' => 2, 'Sticker' => 1],
        ]);

        $this->component(null, 'PCK-COURIER', 'Courier packing', 1, false, [
            'Packing' => ['Courier bag' => 1, 'Bubble wrap sheet' => 1, 'Tape' => 1, 'Crinkle paper shred' => 1],
        ]);
    }

    private function seedMaterials(): void
    {
        $rows = [
            // name, unit, type, unit cost (Rs)
            ['Plaster of Paris', 'g', 'material', 12 / 34],
            ['Water', 'ml', 'material', 0.2],
            ['Release spray', 'ml', 'material', 12],
            ['Acrylic paint', 'ml', 'material', 26 / 6],
            ['Empty paint pot', 'pc', 'material', 35 / 3],
            ['Paint brush', 'pc', 'material', 30],
            ['Polythene', 'pc', 'material', 8],
            ['Background cover', 'pc', 'material', 35],
            ['Crinkle paper shred', 'portion', 'material', 5],
            ['Sticker', 'pc', 'material', 8],
            ['Cardboard box', 'pc', 'material', 120],
            ['Courier bag', 'pc', 'material', 16],
            ['Bubble wrap sheet', 'portion', 'material', 10],
            ['Tape', 'portion', 'material', 2],
            ['Silicone mold wear', 'use', 'overhead', 5],
            ['Making labor', 'minute', 'overhead', 2],
            ['Figure wastage allowance', 'figure', 'overhead', 2],
            ['Material transport', 'figure', 'overhead', 4],
            ['Equipment wear and electricity', 'figure', 'overhead', 2],
            ['Kit assembly labor', 'kit', 'overhead', 10],
            ['Kit other cost', 'kit', 'overhead', 4],
            ['Theme mold share', 'Rs', 'overhead', 1],
            ['Packing allowance', 'Rs', 'overhead', 1],
            ['Handling and other', 'Rs', 'overhead', 1],
        ];

        foreach ($rows as [$name, $unit, $type, $cost]) {
            $this->materials[$name] = Material::query()->updateOrCreate(
                ['name' => $name],
                ['unit' => $unit, 'type' => $type, 'unit_cost' => round($cost, 4)],
            );
        }
    }

    /**
     * Create or refresh a component and replace its making stages.
     *
     * @param  array<string, array<string, float|int>>  $stages  stage name => [material name => qty per batch]
     */
    private function component(?Category $category, string $code, string $name, float $yield, bool $keepsStock, array $stages): Component
    {
        $component = Component::query()->updateOrCreate(['code' => $code], [
            'category_id' => $category?->id,
            'name' => $name,
            'yield_qty' => $yield,
            'wastage_pct' => 0,
            'keeps_stock' => $keepsStock,
            'active' => true,
        ]);

        $component->stages()->delete();
        $order = 1;

        foreach ($stages as $stageName => $lines) {
            $stage = $component->stages()->create(['name' => $stageName, 'sort_order' => $order++]);

            foreach ($lines as $material => $qty) {
                $stage->lines()->create(['material_id' => $this->materials[$material]->id, 'qty' => $qty]);
            }
        }

        return $component;
    }
}
