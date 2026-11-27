<?php

namespace Database\Seeders;

use App\Models\Component;
use App\Models\Product;
use App\Services\FigureService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The figure designs inside each DIY painting kit (Vehicle → Car, Bike, Bus ...). Each design
 * copies the making steps of its kit's FIG-{theme} figure so its cost is known.
 *
 * Safe to run again: a design that already exists in its kit is left as it is.
 */
class FigureDesignSeeder extends Seeder
{
    /**
     * Kit theme code => figure names. Flowers has no confirmed figures yet.
     *
     * @var array<string, list<string>>
     */
    private const DESIGNS = [
        'VEH' => ['Car', 'Bike', 'Bus', 'Lorry', 'Helicopter', 'Tractor'],
        'AST' => ['Astronaut', 'Earth', 'Rocket'],
        'CUT' => ['Bunny', 'Bear', 'Butterfly'],
        'FLW' => [],
        'ALP' => ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z'],
        'FRU' => ['Pineapple', 'Watermelon', 'Strawberry', 'Orange'],
    ];

    public function run(FigureService $figures): void
    {
        foreach (self::DESIGNS as $theme => $names) {
            $kit = Product::query()->where('code', "PNT-{$theme}")->first();

            if ($kit === null) {
                continue;
            }

            $template = Component::query()->with('stages.lines')->where('code', "FIG-{$theme}")->first();

            foreach ($names as $name) {
                $exists = Component::query()->where('product_id', $kit->id)->where('kit_group', 'figure')->where('name', $name)->exists();

                if (! $exists) {
                    DB::transaction(fn () => $this->copySteps($template, $figures->createDesign($kit, $name)));
                }
            }
        }
    }

    private function copySteps(?Component $template, Component $design): void
    {
        if ($template === null) {
            return;
        }

        $design->update($template->only('category_id', 'yield_qty', 'wastage_pct', 'uses_materials_when_made'));

        foreach ($template->stages as $stage) {
            $newStage = $design->stages()->create($stage->only('name', 'sort_order'));

            foreach ($stage->lines as $line) {
                $newStage->lines()->create($line->only('material_id', 'qty', 'wastage_pct'));
            }
        }
    }
}
