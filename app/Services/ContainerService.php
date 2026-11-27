<?php

namespace App\Services;

use App\Models\Material;
use App\Models\MaterialContainer;
use App\Models\PackRecord;
use App\Models\SaleItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Open / finished tracking for materials that cannot be measured per kit (glue, varnish...).
 * Stock leaves when a container is opened; finishing it records how many kits it lasted.
 */
class ContainerService
{
    public function __construct(private StockService $stock) {}

    public function open(Material $material, float $size, string $label, Carbon $on, ?string $note = null): MaterialContainer
    {
        return DB::transaction(function () use ($material, $size, $label, $on, $note) {
            $material = Material::query()->lockForUpdate()->findOrFail($material->id);

            if (! $material->track_by_container) {
                throw new InvalidArgumentException("{$material->name} is not tracked by open and finished containers.");
            }

            if ($size > $material->stock + 1e-9) {
                throw new InvalidArgumentException("Only {$material->stock} {$material->unit} of {$material->name} is unopened.");
            }

            $container = $material->containers()->create([
                'label' => $label,
                'size' => $size,
                'cost' => round($size * $material->unit_cost, 2),
                'opened_on' => $on->toDateString(),
                'note' => $note,
            ]);
            $this->stock->move($material->id, -$size, 'opened', $container, $on);

            return $container;
        });
    }

    public function finish(MaterialContainer $container, Carbon $on, float $kitsMade): void
    {
        if ($container->finished_on) {
            throw new InvalidArgumentException('This container is already finished.');
        }

        if ($on->lt($container->opened_on)) {
            throw new InvalidArgumentException('The finished date cannot be before the opened date.');
        }

        $container->update(['finished_on' => $on->toDateString(), 'kits_made' => $kitsMade]);
    }

    public function reopen(MaterialContainer $container): void
    {
        $container->update(['finished_on' => null, 'kits_made' => null]);
    }

    /**
     * Undo an "open" made by mistake: the quantity goes back into stock.
     */
    public function cancel(MaterialContainer $container): void
    {
        if ($container->finished_on) {
            throw new InvalidArgumentException('Reopen the container before removing it.');
        }

        DB::transaction(function () use ($container) {
            $this->stock->move($container->material_id, $container->size, 'adjustment', $container);
            $container->delete();
        });
    }

    /**
     * Kits packed, plus kits sold without being packed first, since the container was opened
     * whose contents include this material. Only a suggestion: the user can overwrite it.
     */
    public function kitsSinceOpened(MaterialContainer $container): float
    {
        $id = $container->material_id;
        $from = $container->opened_on->toDateString();

        $packed = PackRecord::query()->whereDate('packed_on', '>=', $from)->get(['qty', 'contents'])
            ->filter(fn (PackRecord $record) => $this->contains($record->contents ?? [], $id))
            ->sum('qty');

        $sold = SaleItem::query()
            ->whereHas('sale', fn ($query) => $query->whereDate('sold_on', '>=', $from))
            ->get(['id', 'sale_id', 'contents'])
            ->filter(fn (SaleItem $item) => $this->contains($item->contents['lines'] ?? [], $id))
            ->sum(fn (SaleItem $item) => (float) ($item->contents['unpacked_qty'] ?? 0));

        return round((float) $packed + (float) $sold, 3);
    }

    /**
     * Real usage worked out from finished containers.
     *
     * @return array{qty_per_kit: float, cost_per_kit: float, containers: int}|null
     */
    public function measured(Material $material): ?array
    {
        $finished = $material->containers()->whereNotNull('finished_on')->where('kits_made', '>', 0)->get();
        $kits = (float) $finished->sum('kits_made');

        if ($kits <= 0) {
            return null;
        }

        return [
            'qty_per_kit' => round($finished->sum('size') / $kits, 3),
            'cost_per_kit' => round($finished->sum('cost') / $kits, 2),
            'containers' => $finished->count(),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines  contents snapshot lines
     */
    private function contains(array $lines, int $materialId): bool
    {
        foreach ($lines as $line) {
            if (($line['kind'] ?? null) === 'material' && (int) $line['id'] === $materialId) {
                return true;
            }

            foreach ($line['recipe'] ?? [] as $ingredient) {
                if ((int) ($ingredient['material_id'] ?? 0) === $materialId) {
                    return true;
                }
            }
        }

        return false;
    }
}
