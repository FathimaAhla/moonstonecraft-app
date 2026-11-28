<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Component;
use App\Models\Material;
use App\Models\Product;
use App\Services\KitCounts;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * The DIY painting kit price list (database/seeders/data/painting-kits.tsv): one product per
 * theme and one option per row. Each option is built from parts so the system works out its cost:
 * theme mold share + figures + paint pots + brushes + assembly + packing for its size.
 *
 * Safe to run again: products and options are matched by code and their parts are replaced.
 */
class PaintingKitSeeder extends Seeder
{
    public function run(): void
    {
        $category = Category::query()->where('code_prefix', 'PNT')->firstOrFail();
        $component = fn (string $code) => Component::query()->where('code', $code)->firstOrFail();
        $material = fn (string $name) => Material::query()->where('name', $name)->firstOrFail();

        $pot = $component('PAINT-POT');
        $assembly = $component('KIT-ASM');
        $courier = $component('PCK-COURIER');
        $brush = $material('Paint brush');
        $moldShare = $material('Theme mold share');
        $products = [];

        foreach ($this->rows() as $row) {
            $theme = $row['theme'];
            $products[$theme] ??= Product::query()->updateOrCreate(['code' => "PNT-{$theme}"], [
                'category_id' => $category->id,
                'name' => $this->productName($row['name']),
                'notes' => "Mold {$row['mold']}",
                'active' => true,
            ]);

            $option = $products[$theme]->options()->updateOrCreate(['code' => $row['code']], [
                'name' => $this->optionName($row),
                'retail_price' => $this->money($row['price']),
                'courier_packing_component_id' => $courier->id,
                'active' => true,
            ]);

            $option->parts()->delete();
            $option->parts()->createMany([
                ['material_id' => $moldShare->id, 'qty' => $this->money($row['theme_share'])],
                ['component_id' => $component("FIG-{$theme}")->id, 'qty' => (int) $row['figures']],
                ['component_id' => $pot->id, 'qty' => (int) $row['pots']],
                ['material_id' => $brush->id, 'qty' => (int) $row['brushes']],
                ['component_id' => $assembly->id, 'qty' => 1],
                ['component_id' => $component('PCK-'.strtoupper($row['size']))->id, 'qty' => 1],
            ]);
            app(KitCounts::class)->sync($option);
        }
    }

    /**
     * @return list<array<string, string>>
     */
    private function rows(): array
    {
        $lines = file(database_path('seeders/data/painting-kits.tsv'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            throw new RuntimeException('painting-kits.tsv not found.');
        }

        $header = explode("\t", array_shift($lines));

        return array_map(fn (string $line) => array_combine($header, explode("\t", $line)), $lines);
    }

    /**
     * "Custom – Vehicle Kit 🚗 – 5 Figures…" or "Vehicle Kit 🚗 – 1 Figure…" gives "Vehicle Kit 🚗".
     */
    private function productName(string $name): string
    {
        $parts = array_map('trim', explode('–', $name));

        return str_starts_with($name, 'Custom') ? $parts[1] : $parts[0];
    }

    /**
     * @param  array<string, string>  $row
     */
    private function optionName(array $row): string
    {
        $parts = array_map('trim', explode('–', $row['name']));
        $contents = end($parts);

        return (str_starts_with($row['name'], 'Custom') ? 'Custom: ' : '')."{$contents} · {$row['figure_choice']}";
    }

    private function money(string $value): float
    {
        return (float) str_replace(',', '', $value);
    }
}
