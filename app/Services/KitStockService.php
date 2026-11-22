<?php

namespace App\Services;

use App\Models\Component;
use App\Models\Material;
use App\Models\ProductOption;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Making parts, packing kits and working out how many more you can make.
 */
class KitStockService
{
    /** Reasons you can pick when correcting part or kit stock by hand. */
    public const ADJUST_REASONS = ['adjustment', 'damaged', 'missing', 'found'];

    public function __construct(private Costing $costing, private StockService $stock) {}

    /**
     * You made $qty of a part (figures, paint pots). Its stock goes up, and when
     * $useMaterials is on the recipe materials are taken out of material stock.
     */
    public function make(Component $component, float $qty, ?Carbon $on = null, bool $useMaterials = true, ?string $note = null): void
    {
        $this->ensureKeepsStock($component);

        DB::transaction(function () use ($component, $qty, $on, $useMaterials, $note) {
            if ($useMaterials) {
                $this->stock->deduct($this->costing->componentUsage($component, $qty), 'making', $component, $on);
            }

            $this->stock->moveComponent($component->id, $qty, 'made', null, $on, $note);
        });
    }

    /**
     * Pack $qty kits: parts out, ready kit stock in.
     */
    public function pack(ProductOption $option, float $qty, ?Carbon $on = null, ?string $note = null): void
    {
        DB::transaction(function () use ($option, $qty, $on, $note) {
            $this->stock->deduct($this->costing->optionStockUsage($option, $qty), 'packing', $option, $on);
            $this->stock->moveOption($option->id, $qty, 'packed', null, $on, $note);
        });
    }

    /**
     * Open $qty packed kits: ready kit stock out, parts back in.
     */
    public function unpack(ProductOption $option, float $qty, ?Carbon $on = null, ?string $note = null): void
    {
        DB::transaction(function () use ($option, $qty, $on, $note) {
            $this->stock->moveOption($option->id, -$qty, 'unpacked', null, $on, $note);
            $this->stock->restore($this->costing->optionStockUsage($option, $qty), 'unpacking', $option, $on);
        });
    }

    /**
     * Hand correction of part or kit stock (damage, count fix), with a reason.
     *
     * @param  'components'|'options'  $kind
     */
    public function adjust(string $kind, int $id, float $qty, string $reason, ?string $note = null): void
    {
        if (! in_array($reason, self::ADJUST_REASONS, true)) {
            throw new InvalidArgumentException("Unknown reason [{$reason}].");
        }

        DB::transaction(fn () => $this->stock->moveItem($kind, $id, $qty, $reason, null, null, null, $note));
    }

    /**
     * What selling $qty of an option takes out of stock: ready packed kits first,
     * then the parts for any kits still to be packed.
     *
     * @return array{materials: array<int, float>, components: array<int, float>, options: array<int, float>}
     */
    public function saleUsage(ProductOption $option, float $qty): array
    {
        $ready = min(max($option->stock, 0), $qty);
        $bag = StockService::bag($ready > 0 ? ['options' => [$option->id => $ready]] : []);

        if ($qty - $ready > 0) {
            $bag = StockService::mergeBags($bag, $this->costing->optionStockUsage($option, $qty - $ready));
        }

        return $bag;
    }

    /**
     * How many more kits the parts in stock can make, and which part runs out first.
     * Parts are shared, so this assumes you make only this option.
     *
     * @return array{can_make: int|null, limit: string|null}
     */
    public function canMakeOption(ProductOption $option): array
    {
        return $this->canMake($this->costing->optionStockUsage($option, 1));
    }

    /**
     * How many more of a part the materials in stock can make (e.g. paint pots from paint).
     *
     * @return array{can_make: int|null, limit: string|null}
     */
    public function canMakeComponent(Component $component): array
    {
        return $this->canMake(StockService::bag($this->costing->componentUsage($component, 1)));
    }

    /**
     * @param  array{materials: array<int, float>, components: array<int, float>, options: array<int, float>}  $perUnit
     * @return array{can_make: int|null, limit: string|null}
     */
    private function canMake(array $perUnit): array
    {
        $best = null;
        $limit = null;
        $items = [
            ...Material::query()->whereIn('id', array_keys($perUnit['materials']))->get()->map(fn ($m) => [$m->name, $m->stock, $perUnit['materials'][$m->id]]),
            ...Component::query()->whereIn('id', array_keys($perUnit['components']))->get()->map(fn ($c) => ["{$c->code} {$c->name}", $c->stock, $perUnit['components'][$c->id]]),
        ];

        foreach ($items as [$name, $stock, $need]) {
            if ($need <= 0) {
                continue;
            }

            $possible = (int) floor(max(0, $stock) / $need + 1e-9);

            if ($best === null || $possible < $best) {
                $best = $possible;
                $limit = $name;
            }
        }

        return ['can_make' => $best, 'limit' => $limit];
    }

    private function ensureKeepsStock(Component $component): void
    {
        if (! $component->keeps_stock) {
            throw new InvalidArgumentException("{$component->code} does not keep its own stock.");
        }
    }
}
