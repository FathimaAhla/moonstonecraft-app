<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Component;
use App\Models\FigureCombo;
use App\Models\Material;
use App\Models\OptionPart;
use App\Models\ProductOption;

/**
 * How many figures, paint pots and brushes a kit holds, read from its recipe.
 *
 * In a category that picks figures and paint (DIY painting kits), the recipe's figure and pot
 * lines only say how many: which figure set and paint set go in is picked when packing, selling
 * or later, and only those picks take stock. The recipe lines still count towards the cost estimate.
 */
class KitCounts
{
    public const PICKS_NONE = 'none';

    public const PICKS_FIGURES_PAINT = 'figures_paint';

    /** @var array<string, string> */
    public const PICKS = [
        self::PICKS_NONE => 'Nothing (sold as packed)',
        self::PICKS_FIGURES_PAINT => 'Figures and a paint set',
    ];

    public static function picksFiguresAndPaint(ProductOption $option): bool
    {
        $option->loadMissing('product.category');

        return $option->product?->category?->kit_picks === self::PICKS_FIGURES_PAINT;
    }

    /**
     * Which count a recipe line adds to: figures, pots, brushes, or none.
     */
    public static function slot(?Component $component, ?Material $material): ?string
    {
        if ($component !== null) {
            return match (KitGroups::resolve($component->kit_group, $component->name)) {
                'figure', 'figure_set' => 'figures',
                'paint' => 'pots',
                'brush' => 'brushes',
                default => null,
            };
        }

        if ($material !== null) {
            $group = KitGroups::resolve($material->kit_group, $material->name, ! $material->is_purchasable);

            return match (true) {
                $group === 'figure' => 'figures',
                $group === 'paint' && ! PaintSetService::isPaint($material) => 'pots',
                $group === 'brush' => 'brushes',
                default => null,
            };
        }

        return null;
    }

    /**
     * A recipe line whose stock is taken by the picked figure set or paint set instead.
     */
    public static function takenByPicks(ProductOption $option, OptionPart $part): bool
    {
        $slot = self::slot($part->component, $part->material);

        return ($slot === 'figures' && $option->figures_per_kit > 0) || ($slot === 'pots' && $option->paint_pots_per_kit > 0);
    }

    /**
     * @return array{figures: int, pots: int, brushes: int}
     */
    public function fromRecipe(ProductOption $option): array
    {
        $option->loadMissing('parts.component', 'parts.material');
        $counts = ['figures' => 0.0, 'pots' => 0.0, 'brushes' => 0.0];

        foreach ($option->parts as $part) {
            $slot = self::slot($part->component, $part->material);

            if ($slot !== null) {
                $counts[$slot] += $part->qty * $this->eachHolds($part->component, $slot);
            }
        }

        return array_map(fn (float $count) => (int) round($count), $counts);
    }

    /**
     * Set the kit's figure and pot counts from its recipe. Kits in categories that pick nothing
     * get no counts, so nothing is asked when packing or selling them.
     */
    public function sync(ProductOption $option): void
    {
        $counts = self::picksFiguresAndPaint($option) ? $this->fromRecipe($option->load('parts.component', 'parts.material')) : ['figures' => 0, 'pots' => 0];

        $option->update(['figures_per_kit' => min($counts['figures'], 255), 'paint_pots_per_kit' => min($counts['pots'], 255)]);
    }

    public function syncCategory(Category $category): void
    {
        ProductOption::query()->whereHas('product', fn ($query) => $query->where('category_id', $category->id))->get()
            ->each(fn (ProductOption $option) => $this->sync($option));
    }

    /**
     * How many figures or pots one piece of a recipe line holds: a figure set holds its size,
     * a cut paint set holds its pots.
     */
    private function eachHolds(?Component $component, string $slot): float
    {
        if ($component === null) {
            return 1;
        }

        if ($slot === 'figures' && $component->kit_group === 'figure_set') {
            return (float) (FigureCombo::query()->where('component_id', $component->id)->value('size') ?? 1);
        }

        if ($slot === 'pots' && $component->pot_colours) {
            return count($component->pot_colours);
        }

        return 1;
    }
}
