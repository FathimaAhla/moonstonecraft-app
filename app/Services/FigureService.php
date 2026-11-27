<?php

namespace App\Services;

use App\Models\Component;
use App\Models\FigureCombo;
use App\Models\FigureComboItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Figure designs grouped under their kit (Vehicle Kit: Car, Bike, Bus...) and saved combinations
 * of them. Each design keeps its own stock. Each combination has a set part: "make sets" moves single
 * figures into made-up sets, and selling or packing uses sets first, then single figures.
 */
class FigureService
{
    public function __construct(private Costing $costing, private StockService $stock) {}

    /**
     * Figure designs linked to a kit, or to the given kit only.
     *
     * @return Collection<int, Component>
     */
    public function designs(?int $kitId = null): Collection
    {
        return $this->figureParts()->whereNotNull('product_id')->when($kitId !== null, fn ($query) => $query->where('product_id', $kitId))->get()
            ->filter(fn (Component $component) => self::isDesign($component))->values();
    }

    /**
     * Designs added before figures belonged to a kit (they have only a typed family name).
     *
     * @return Collection<int, Component>
     */
    public function unlinkedDesigns(): Collection
    {
        return $this->figureParts()->whereNull('product_id')->whereNotNull('figure_family')->get()
            ->filter(fn (Component $component) => self::isDesign($component))->values();
    }

    public function createDesign(Product $kit, string $name): Component
    {
        return DB::transaction(fn () => Component::query()->create([
            'code' => $this->nextCode(),
            'name' => $name,
            'kit_group' => 'figure',
            'product_id' => $kit->id,
            'yield_qty' => 1,
            'wastage_pct' => 0,
            'keeps_stock' => true,
            'active' => true,
        ]));
    }

    /**
     * Find the saved combination of these designs, or save it. The same designs in any order
     * are the same combination. All designs come from one kit.
     *
     * @param  array<int|string, int|float|string>  $designs  component id => how many
     */
    public function combo(array $designs, ?string $name = null): FigureCombo
    {
        $designs = collect($designs)->map(fn ($qty) => (int) $qty)->filter(fn (int $qty) => $qty > 0)->sortKeys();

        if ($designs->isEmpty()) {
            throw new InvalidArgumentException('Pick at least one figure.');
        }

        $components = Component::query()->with('kit')->whereIn('id', $designs->keys())->get()->keyBy('id');
        $kitIds = $components->pluck('product_id')->unique();

        if ($components->count() !== $designs->count() || $kitIds->count() !== 1 || $kitIds->first() === null) {
            throw new InvalidArgumentException('A figure set uses figures from one kit.');
        }

        $kit = $components->first()->kit;
        $signature = "kit{$kit->id}|".$designs->map(fn (int $qty, $id) => "{$id}x{$qty}")->implode(',');

        return DB::transaction(function () use ($signature, $kit, $designs, $components, $name) {
            $existing = FigureCombo::query()->where('signature', $signature)->first();

            if ($existing) {
                return $existing;
            }

            $combo = FigureCombo::query()->create([
                'product_id' => $kit->id,
                'family' => $kit->name,
                'name' => $name ?: $designs->map(fn (int $qty, $id) => ($qty > 1 ? "{$qty} × " : '').$components[$id]->name)->implode(' + '),
                'size' => $designs->sum(),
                'signature' => $signature,
            ]);

            foreach ($designs as $id => $qty) {
                $combo->items()->create(['component_id' => $id, 'qty' => $qty]);
            }

            $this->setPart($combo);

            return $combo;
        });
    }

    /**
     * The part that counts made-up sets of this combination, created the first time it is needed.
     */
    public function setPart(FigureCombo $combo): Component
    {
        if ($combo->component_id && $combo->setPart) {
            return $combo->setPart;
        }

        $part = Component::query()->create([
            'code' => $this->nextCode('FS-'),
            'name' => "Set: {$combo->name}",
            'kit_group' => 'figure_set',
            'product_id' => $combo->product_id,
            'yield_qty' => 1,
            'wastage_pct' => 0,
            'keeps_stock' => true,
            'active' => true,
        ]);
        $combo->update(['component_id' => $part->id]);
        $combo->setRelation('setPart', $part);

        return $part;
    }

    /**
     * You put together $qty sets: set stock goes up and each single figure goes down. Never more
     * sets than the single figures in stock allow.
     */
    public function makeSets(FigureCombo $combo, float $qty, ?Carbon $on = null, ?string $note = null): void
    {
        DB::transaction(function () use ($combo, $qty, $on, $note) {
            $canMake = $this->summary($combo->load('items.component'))['from_figures'];

            if ($qty > $canMake) {
                throw new InvalidArgumentException("Only {$canMake} sets can be made from the figures in stock.");
            }

            $part = $this->setPart($combo);

            foreach ($combo->items as $item) {
                $this->stock->moveComponent($item->component_id, -$qty * $item->qty, 'made', $part, $on, 'Made into sets');
            }

            $this->stock->moveComponent($part->id, $qty, 'made', null, $on, $note);
        });
    }

    /**
     * Plan parts to give $kits kits this combination: made-up sets first, then single figures
     * for the rest.
     *
     * @return list<array{component_id: int, material_id: null, qty: float}>
     */
    public function takeParts(FigureCombo $combo, float $kits): array
    {
        $part = $this->setPart($combo);
        $fromSets = min($kits, max($part->fresh()->stock, 0));
        $rest = round($kits - $fromSets, 3);
        $parts = $fromSets > 0 ? [['component_id' => $part->id, 'material_id' => null, 'qty' => $fromSets]] : [];

        if ($rest > 0) {
            foreach ($combo->items as $item) {
                $parts[] = ['component_id' => $item->component_id, 'material_id' => null, 'qty' => $rest * $item->qty];
            }
        }

        return $parts;
    }

    /**
     * Plan parts for one combination (one kit's figures).
     *
     * @return list<array{component_id: int, material_id: null, qty: float}>
     */
    public function parts(FigureCombo $combo): array
    {
        return $combo->items->map(fn (FigureComboItem $item) => ['component_id' => $item->component_id, 'material_id' => null, 'qty' => (float) $item->qty])->all();
    }

    /**
     * A combination as the screens show it: made-up sets in stock, how many more the single
     * figures can make, and the two together (can_make).
     *
     * @return array<string, mixed>
     */
    public function summary(FigureCombo $combo): array
    {
        $combo->loadMissing('items.component.stages.lines.material', 'setPart');
        $fromFigures = $combo->items->map(fn (FigureComboItem $item) => (int) floor(max($item->component->stock, 0) / $item->qty + 1e-9))->min() ?? 0;
        $sets = (float) ($combo->setPart?->stock ?? 0);

        return [
            'id' => $combo->id,
            'product_id' => $combo->product_id,
            'family' => $combo->family,
            'name' => $combo->name,
            'size' => $combo->size,
            'items' => $combo->items->map(fn (FigureComboItem $item) => ['id' => $item->component_id, 'name' => $item->component->name, 'qty' => $item->qty, 'stock' => $item->component->stock])->values()->all(),
            'set_id' => $combo->component_id,
            'stock' => $sets,
            'reorder_level' => (float) ($combo->setPart?->reorder_level ?? 0),
            'from_figures' => $fromFigures,
            'can_make' => (int) max($sets, 0) + $fromFigures,
            'unit_cost' => round($this->costing->snapshot($this->parts($combo), 1)['cost'], 2),
        ];
    }

    /**
     * Saved combinations, optionally only one kit and size.
     *
     * @return Collection<int, FigureCombo>
     */
    public function combos(?int $kitId = null, ?int $size = null): Collection
    {
        return FigureCombo::query()->with('items.component.stages.lines.material', 'setPart')
            ->when($kitId !== null, fn ($query) => $query->where('product_id', $kitId))
            ->when($size !== null, fn ($query) => $query->where('size', $size))
            ->orderBy('size')->orderBy('name')->get();
    }

    /**
     * @return Builder<Component>
     */
    private function figureParts(): Builder
    {
        return Component::query()->with('stages.lines.material')->where('keeps_stock', true)->orderBy('name');
    }

    private static function isDesign(Component $component): bool
    {
        return KitGroups::resolve($component->kit_group, $component->name) === 'figure';
    }

    private function nextCode(string $prefix = 'FG-'): string
    {
        $last = Component::query()->where('code', 'like', $prefix.'%')->lockForUpdate()->pluck('code')
            ->map(fn (string $code) => (int) substr($code, strlen($prefix)))->max() ?? 0;

        return $prefix.str_pad((string) ($last + 1), 3, '0', STR_PAD_LEFT);
    }
}
