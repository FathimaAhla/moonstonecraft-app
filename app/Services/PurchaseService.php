<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Material;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class PurchaseService
{
    public function __construct(private StockService $stock) {}

    /**
     * Record a purchase with landed cost allocation, stock and average cost updates.
     *
     * @param  array{purchased_on: string, supplier_id?: int|null, transport_cost?: float|null, refreshment_cost?: float|null, other_cost?: float|null, refreshment_as_stock_cost?: bool, notes?: string|null}  $header
     * @param  list<array{material_id: int, qty: float, line_cost: float, purchase_unit?: string|null, conversion_factor?: float|null, material_pack_size_id?: int|null}>  $lines
     */
    public function record(array $header, array $lines): Purchase
    {
        return DB::transaction(function () use ($header, $lines) {
            $transport = (float) ($header['transport_cost'] ?? 0);
            $refreshment = (float) ($header['refreshment_cost'] ?? 0);
            $other = (float) ($header['other_cost'] ?? 0);
            $refreshmentAsStock = (bool) ($header['refreshment_as_stock_cost'] ?? false);
            $date = Carbon::parse($header['purchased_on']);

            $lines = array_map(fn (array $line) => $this->prepareLine($line), $lines);
            $itemsTotal = round(array_sum(array_map(fn ($line) => (float) $line['line_cost'], $lines)), 2);
            $extra = $transport + $other + ($refreshmentAsStock ? $refreshment : 0);
            $allocations = CostMath::allocateByValue(array_map(fn ($line) => (float) $line['line_cost'], $lines), $extra);

            $purchase = Purchase::query()->create([
                'purchase_no' => $this->nextNumber($date),
                'purchased_on' => $date->toDateString(),
                'supplier_id' => $header['supplier_id'] ?? null,
                'items_total' => $itemsTotal,
                'transport_cost' => $transport,
                'refreshment_cost' => $refreshment,
                'other_cost' => $other,
                'grand_total' => round($itemsTotal + $transport + $refreshment + $other, 2),
                'refreshment_as_stock_cost' => $refreshmentAsStock,
                'notes' => $header['notes'] ?? null,
            ]);

            foreach ($lines as $index => $line) {
                $qty = (float) $line['converted'];
                $allocated = $allocations[$index];
                $landed = ((float) $line['line_cost'] + $allocated) / $qty;

                $purchase->items()->create([
                    'material_id' => $line['material_id'],
                    'material_pack_size_id' => $line['material_pack_size_id'],
                    'qty' => $line['qty'],
                    'purchase_unit' => $line['purchase_unit'],
                    'conversion_factor' => $line['conversion_factor'],
                    'converted_qty' => $qty,
                    'line_cost' => $line['line_cost'],
                    'extra_allocated' => round($allocated, 2),
                    'landed_unit_cost' => round($landed, 4),
                ]);

                $material = Material::query()->lockForUpdate()->findOrFail($line['material_id']);

                if ($material->tracks_stock) {
                    $average = CostMath::weightedAverage($material->stock, $material->purchase_unit_cost, $qty, $landed);
                    $this->stock->move($material->id, $qty, 'purchase', $purchase, $date, $average);
                } else {
                    $material->purchase_unit_cost = round($landed, 4);
                    $material->save();
                }
            }

            if (! $refreshmentAsStock && $refreshment > 0) {
                Expense::query()->create([
                    'spent_on' => $date->toDateString(),
                    'category' => 'Purchase refreshment',
                    'description' => "Refreshment for {$purchase->purchase_no}",
                    'amount' => $refreshment,
                    'ref_type' => $purchase->getMorphClass(),
                    'ref_id' => $purchase->id,
                ]);
            }

            return $purchase;
        });
    }

    /**
     * Replace a purchase's lines and trip costs, reversing the old stock/expense effects first
     * and re-applying the new ones. The purchase number and id are kept. As with delete(),
     * weighted-average costs of tracked materials are not rolled back to their pre-purchase value.
     *
     * @param  array{purchased_on: string, supplier_id?: int|null, transport_cost?: float|null, refreshment_cost?: float|null, other_cost?: float|null, refreshment_as_stock_cost?: bool, notes?: string|null}  $header
     * @param  list<array{material_id: int, qty: float, line_cost: float, purchase_unit?: string|null, conversion_factor?: float|null, material_pack_size_id?: int|null}>  $lines
     */
    public function update(Purchase $purchase, array $header, array $lines): Purchase
    {
        return DB::transaction(function () use ($purchase, $header, $lines) {
            $oldItems = $purchase->items()->with('material')->get();

            foreach ($oldItems as $item) {
                if ($item->material->tracks_stock) {
                    $this->stock->move($item->material_id, -($item->converted_qty ?? $item->qty), 'adjustment', $purchase);
                }
            }

            Expense::query()->where('ref_type', $purchase->getMorphClass())->where('ref_id', $purchase->id)->delete();
            $purchase->items()->delete();

            foreach ($oldItems as $item) {
                $this->refreshUntrackedPurchaseCost($item->material);
            }

            $transport = (float) ($header['transport_cost'] ?? 0);
            $refreshment = (float) ($header['refreshment_cost'] ?? 0);
            $other = (float) ($header['other_cost'] ?? 0);
            $refreshmentAsStock = (bool) ($header['refreshment_as_stock_cost'] ?? false);
            $date = Carbon::parse($header['purchased_on']);

            $lines = array_map(fn (array $line) => $this->prepareLine($line), $lines);
            $itemsTotal = round(array_sum(array_map(fn ($line) => (float) $line['line_cost'], $lines)), 2);
            $extra = $transport + $other + ($refreshmentAsStock ? $refreshment : 0);
            $allocations = CostMath::allocateByValue(array_map(fn ($line) => (float) $line['line_cost'], $lines), $extra);

            $purchase->update([
                'purchased_on' => $date->toDateString(),
                'supplier_id' => $header['supplier_id'] ?? null,
                'items_total' => $itemsTotal,
                'transport_cost' => $transport,
                'refreshment_cost' => $refreshment,
                'other_cost' => $other,
                'grand_total' => round($itemsTotal + $transport + $refreshment + $other, 2),
                'refreshment_as_stock_cost' => $refreshmentAsStock,
                'notes' => $header['notes'] ?? null,
            ]);

            foreach ($lines as $index => $line) {
                $qty = (float) $line['converted'];
                $allocated = $allocations[$index];
                $landed = ((float) $line['line_cost'] + $allocated) / $qty;

                $purchase->items()->create([
                    'material_id' => $line['material_id'],
                    'material_pack_size_id' => $line['material_pack_size_id'],
                    'qty' => $line['qty'],
                    'purchase_unit' => $line['purchase_unit'],
                    'conversion_factor' => $line['conversion_factor'],
                    'converted_qty' => $qty,
                    'line_cost' => $line['line_cost'],
                    'extra_allocated' => round($allocated, 2),
                    'landed_unit_cost' => round($landed, 4),
                ]);

                $material = Material::query()->lockForUpdate()->findOrFail($line['material_id']);

                if ($material->tracks_stock) {
                    $average = CostMath::weightedAverage($material->stock, $material->purchase_unit_cost, $qty, $landed);
                    $this->stock->move($material->id, $qty, 'purchase', $purchase, $date, $average);
                } else {
                    $material->purchase_unit_cost = round($landed, 4);
                    $material->save();
                }
            }

            if (! $refreshmentAsStock && $refreshment > 0) {
                Expense::query()->create([
                    'spent_on' => $date->toDateString(),
                    'category' => 'Purchase refreshment',
                    'description' => "Refreshment for {$purchase->purchase_no}",
                    'amount' => $refreshment,
                    'ref_type' => $purchase->getMorphClass(),
                    'ref_id' => $purchase->id,
                ]);
            }

            return $purchase;
        });
    }

    /**
     * Delete a purchase and reverse its stock (tracked items) and expense.
     * Weighted-average costs of tracked items are not rolled back.
     */
    public function delete(Purchase $purchase): void
    {
        DB::transaction(function () use ($purchase) {
            foreach ($purchase->items as $item) {
                if ($item->material->tracks_stock) {
                    $this->stock->move($item->material_id, -($item->converted_qty ?? $item->qty), 'adjustment', $purchase);
                }
            }

            Expense::query()->where('ref_type', $purchase->getMorphClass())->where('ref_id', $purchase->id)->delete();
            $purchase->delete();

            foreach ($purchase->items as $item) {
                $this->refreshUntrackedPurchaseCost($item->material);
            }

            if ($purchase->receipt_path) {
                Storage::disk('local')->delete($purchase->receipt_path);
            }
        });
    }

    /**
     * Untracked materials take the latest remaining landed cost, which history supports exactly.
     * Tracked materials keep their weighted average: it cannot be rebuilt reliably from history.
     */
    private function refreshUntrackedPurchaseCost(Material $material): void
    {
        if ($material->tracks_stock) {
            return;
        }

        $latest = PurchaseItem::query()->where('material_id', $material->id)->latest('id')->first();

        if ($latest) {
            $material->purchase_unit_cost = $latest->landed_unit_cost;
            $material->save();
        }
    }

    /**
     * Work out the unit and conversion of a line. A chosen pack size wins over typed values.
     *
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function prepareLine(array $line): array
    {
        $material = Material::query()->findOrFail($line['material_id']);

        if (! $material->is_purchasable) {
            throw new InvalidArgumentException("{$material->name} is not marked purchasable.");
        }

        $packSize = empty($line['material_pack_size_id']) ? null : $material->packSizes()->findOrFail($line['material_pack_size_id']);
        $factor = $packSize?->size ?? ((float) ($line['conversion_factor'] ?? 0) ?: ($material->purchase_unit ? $material->purchase_conversion : 1.0));

        return array_merge($line, [
            'material_pack_size_id' => $packSize?->id,
            'conversion_factor' => $factor,
            'purchase_unit' => $packSize?->name ?? $line['purchase_unit'] ?? $material->purchase_unit,
            'converted' => round((float) $line['qty'] * $factor, 3),
        ]);
    }

    private function nextNumber(Carbon $date): string
    {
        $prefix = 'PUR-'.$date->year.'-';
        $last = Purchase::query()->where('purchase_no', 'like', $prefix.'%')->max('purchase_no');
        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
