<?php

namespace App\Services;

use App\Models\Component;
use App\Models\Material;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Paint sets: strips of filled paint pots cut into 1, 2, 3, 4 or 6 pot sets of chosen colours.
 *
 * A paint set is a part (component) in the "paint" kit section that keeps its own stock and
 * uses its paint when made, so making 20 sets takes the paint and empty pots out of stock at
 * once. Which set goes into a sold kit is picked when selling (see AssignmentService).
 */
class PaintSetService
{
    /** How many pots a cut set can have. */
    public const POT_COUNTS = [1, 2, 3, 4, 6];

    public function __construct(private Costing $costing) {}

    /**
     * Create a paint set part from its colours (one paint material per pot).
     *
     * @param  list<int>  $colourIds  paint material ids, one per pot, in order
     */
    public function create(array $colourIds, float $mlPerPot, ?int $potMaterialId = null, ?string $name = null): Component
    {
        if (! in_array(count($colourIds), self::POT_COUNTS, true)) {
            throw new InvalidArgumentException('A paint set has 1, 2, 3, 4 or 6 pots.');
        }

        return DB::transaction(function () use ($colourIds, $mlPerPot, $potMaterialId, $name) {
            $colours = Material::query()->whereIn('id', $colourIds)->get()->keyBy('id');
            $count = count($colourIds);
            $label = collect($colourIds)->map(fn (int $id) => self::colourName($colours[$id]))->implode(' / ');

            $component = Component::query()->create([
                'code' => $this->nextCode(),
                'name' => $name ?: "{$count}-pot {$label}",
                'kit_group' => 'paint',
                'yield_qty' => 1,
                'wastage_pct' => 0,
                'keeps_stock' => true,
                'uses_materials_when_made' => true,
                'pot_colours' => array_values(array_map('intval', $colourIds)),
                'active' => true,
            ]);

            $stage = $component->stages()->create(['name' => 'Fill and cut', 'sort_order' => 1]);

            foreach (collect($colourIds)->countBy() as $materialId => $pots) {
                $stage->lines()->create(['material_id' => $materialId, 'qty' => round($pots * $mlPerPot, 3)]);
            }

            if ($potMaterialId) {
                $stage->lines()->create(['material_id' => $potMaterialId, 'qty' => $count]);
            }

            return $component;
        });
    }

    /**
     * Every paint set part, newest naming first.
     *
     * @return Collection<int, Component>
     */
    public function all(): Collection
    {
        return Component::query()->with('stages.lines.material')->where('kit_group', 'paint')
            ->where('keeps_stock', true)->orderBy('name')->get();
    }

    /**
     * The colours in a paint set, one entry per pot in order (Red, Yellow, Blue), with the ml
     * of paint each pot takes.
     *
     * @return list<array{id: int, name: string, colour: string|null, ml: float}>
     */
    public function colours(Component $component): array
    {
        $component->loadMissing('stages.lines.material');
        $lines = $component->stages->flatMap->lines->filter(fn ($line) => $line->material && self::isPaint($line->material))->keyBy('material_id');

        if (! $component->pot_colours) {
            return $lines->map(fn ($line) => ['id' => $line->material_id, 'name' => self::colourName($line->material), 'colour' => $line->material->colour, 'ml' => (float) $line->qty])->values()->all();
        }

        $potsOf = array_count_values($component->pot_colours);

        return collect($component->pot_colours)->filter(fn (int $id) => $lines->has($id))->map(fn (int $id) => [
            'id' => $id,
            'name' => self::colourName($lines[$id]->material),
            'colour' => $lines[$id]->material->colour,
            'ml' => round((float) $lines[$id]->qty / $potsOf[$id], 3),
        ])->values()->all();
    }

    /**
     * How many pots a set has (3 for a 3-pot strip).
     */
    public function potCount(Component $component): int
    {
        return $component->pot_colours ? count($component->pot_colours) : count($this->colours($component));
    }

    /**
     * Filled single pots in stock per colour, worked out from the cut sets in stock.
     *
     * @return array<int, float> material id => pots
     */
    public function potsByColour(): array
    {
        $pots = [];

        foreach ($this->all() as $set) {
            foreach ($this->colours($set) as $colour) {
                $pots[$colour['id']] = ($pots[$colour['id']] ?? 0) + max($set->stock, 0);
            }
        }

        return $pots;
    }

    /**
     * A paint set as the screens show it.
     *
     * @return array<string, mixed>
     */
    public function summary(Component $component): array
    {
        return [
            'id' => $component->id,
            'code' => $component->code,
            'name' => $component->name,
            'stock' => $component->stock,
            'reorder_level' => $component->reorder_level,
            'colours' => $this->colours($component),
            'pots' => $this->potCount($component),
            'unit_cost' => round($this->costing->componentUnitCost($component), 2),
        ];
    }

    /**
     * A paint colour has a swatch, or sits in the paint section and is measured by volume or
     * weight. Pots and strips counted in pieces are not colours.
     */
    public static function isPaint(Material $material): bool
    {
        if ($material->colour !== null) {
            return true;
        }

        return KitGroups::resolve($material->kit_group, $material->name) === 'paint'
            && ! in_array(strtolower(trim($material->unit)), ['pc', 'pcs', 'piece', 'pieces', 'nos'], true);
    }

    /**
     * "Paint – Red" reads as "Red" inside a set name.
     */
    public static function colourName(Material $material): string
    {
        return trim((string) preg_replace('/^paint\s*[-–:]?\s*/i', '', $material->name)) ?: $material->name;
    }

    private function nextCode(): string
    {
        $last = Component::query()->where('code', 'like', 'PS-%')->lockForUpdate()->pluck('code')
            ->map(fn (string $code) => (int) substr($code, 3))->max() ?? 0;

        return 'PS-'.str_pad((string) ($last + 1), 3, '0', STR_PAD_LEFT);
    }
}
