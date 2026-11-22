<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Component;
use App\Models\Material;
use App\Models\Product;
use Illuminate\Database\Seeder;

class SampleDataSeeder extends Seeder
{
    /**
     * Seed sample materials, three costed components (figure, painting set, packing) and a figure painting kit.
     */
    public function run(): void
    {
        $m = fn (array $data) => Material::query()->create($data);
        $plaster = $m(['name' => 'Plaster of Paris', 'unit' => 'g', 'stock' => 5000, 'unit_cost' => 0.35, 'reorder_level' => 1000]);
        $water = $m(['name' => 'Water', 'unit' => 'ml', 'stock' => 100000, 'unit_cost' => 0.001]);
        $paintPot = $m(['name' => 'Paint pot', 'unit' => 'pc', 'stock' => 200, 'unit_cost' => 12, 'reorder_level' => 40]);
        $paint = $m(['name' => 'Acrylic paint', 'unit' => 'ml', 'stock' => 3000, 'unit_cost' => 1.8, 'reorder_level' => 500]);
        $brush = $m(['name' => 'Brush', 'unit' => 'pc', 'stock' => 100, 'unit_cost' => 15, 'reorder_level' => 20]);
        $oppBag = $m(['name' => 'OPP bag', 'unit' => 'pc', 'stock' => 300, 'unit_cost' => 6, 'reorder_level' => 50]);
        $sticker = $m(['name' => 'Sticker', 'unit' => 'pc', 'stock' => 300, 'unit_cost' => 4, 'reorder_level' => 50]);
        $insetCard = $m(['name' => 'Inset card', 'unit' => 'pc', 'stock' => 300, 'unit_cost' => 8, 'reorder_level' => 50]);
        $bubbleWrap = $m(['name' => 'Bubble wrap', 'unit' => 'cm', 'stock' => 5000, 'unit_cost' => 0.2, 'reorder_level' => 1000]);
        $labor = $m(['name' => 'Labor', 'unit' => 'hr', 'type' => 'overhead', 'unit_cost' => 150]);
        $electricity = $m(['name' => 'Electricity', 'unit' => 'kWh', 'type' => 'overhead', 'unit_cost' => 40]);
        $mold = $m(['name' => 'Mold wear', 'unit' => 'use', 'type' => 'overhead', 'unit_cost' => 25]);

        $painting = Category::query()->where('code_prefix', 'PNT')->firstOrFail();

        $figure = Component::query()->create(['category_id' => $painting->id, 'code' => 'CMP-FIG-001', 'name' => 'Animal figure', 'yield_qty' => 10, 'wastage_pct' => 5]);
        $mixing = $figure->stages()->create(['name' => 'Mixing', 'sort_order' => 1]);
        $mixing->lines()->create(['material_id' => $plaster->id, 'qty' => 800]);
        $mixing->lines()->create(['material_id' => $water->id, 'qty' => 400]);
        $casting = $figure->stages()->create(['name' => 'Casting', 'sort_order' => 2]);
        $casting->lines()->create(['material_id' => $mold->id, 'qty' => 10]);
        $casting->lines()->create(['material_id' => $labor->id, 'qty' => 1]);
        $figure->stages()->create(['name' => 'Drying', 'sort_order' => 3])->lines()->create(['material_id' => $electricity->id, 'qty' => 1.5]);

        $paintSet = Component::query()->create(['category_id' => $painting->id, 'code' => 'CMP-PNT-001', 'name' => 'Paint pot (filled)', 'yield_qty' => 20, 'wastage_pct' => 3]);
        $filling = $paintSet->stages()->create(['name' => 'Filling', 'sort_order' => 1]);
        $filling->lines()->create(['material_id' => $paintPot->id, 'qty' => 20]);
        $filling->lines()->create(['material_id' => $paint->id, 'qty' => 200]);
        $filling->lines()->create(['material_id' => $labor->id, 'qty' => 0.5]);

        $packing = Component::query()->create(['category_id' => $painting->id, 'code' => 'CMP-PCK-001', 'name' => 'Kit packing', 'yield_qty' => 1]);
        $pack = $packing->stages()->create(['name' => 'Packing', 'sort_order' => 1]);
        $pack->lines()->create(['material_id' => $oppBag->id, 'qty' => 1]);
        $pack->lines()->create(['material_id' => $sticker->id, 'qty' => 1]);
        $pack->lines()->create(['material_id' => $insetCard->id, 'qty' => 1]);

        $courierPacking = Component::query()->create(['code' => 'CMP-PCK-002', 'name' => 'Courier bubble wrap', 'yield_qty' => 1]);
        $courierPacking->stages()->create(['name' => 'Wrapping', 'sort_order' => 1])->lines()->create(['material_id' => $bubbleWrap->id, 'qty' => 60]);

        $kit = Product::query()->create(['category_id' => $painting->id, 'code' => 'PNT-ANM', 'name' => 'Animal figure painting kit']);

        foreach ([[1, 2, 1, 450], [1, 4, 1, 590], [2, 6, 2, 890]] as [$figures, $paints, $brushes, $price]) {
            $option = $kit->options()->create([
                'code' => sprintf('PNT-ANM-%dF%dP', $figures, $paints),
                'name' => "{$figures} figure, {$paints} paints, {$brushes} brush",
                'retail_price' => $price,
                'courier_packing_component_id' => $courierPacking->id,
                'courier_fee_estimate' => 350,
            ]);
            $option->parts()->create(['component_id' => $figure->id, 'qty' => $figures]);
            $option->parts()->create(['component_id' => $paintSet->id, 'qty' => $paints]);
            $option->parts()->create(['material_id' => $brush->id, 'qty' => $brushes]);
            $option->parts()->create(['component_id' => $packing->id, 'qty' => 1]);
        }
    }
}
