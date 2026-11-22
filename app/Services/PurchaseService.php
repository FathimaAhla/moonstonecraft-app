<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Material;
use App\Models\Purchase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PurchaseService
{
    public function __construct(private StockService $stock) {}

    /**
     * Record a purchase with landed cost allocation, stock and average cost updates.
     *
     * @param  array{purchased_on: string, supplier_id?: int|null, transport_cost?: float|null, refreshment_cost?: float|null, other_cost?: float|null, refreshment_as_stock_cost?: bool, notes?: string|null}  $header
     * @param  list<array{material_id: int, qty: float, line_cost: float}>  $lines
     */
    public function record(array $header, array $lines): Purchase
    {
        return DB::transaction(function () use ($header, $lines) {
            $transport = (float) ($header['transport_cost'] ?? 0);
            $refreshment = (float) ($header['refreshment_cost'] ?? 0);
            $other = (float) ($header['other_cost'] ?? 0);
            $refreshmentAsStock = (bool) ($header['refreshment_as_stock_cost'] ?? false);
            $date = Carbon::parse($header['purchased_on']);

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
                $qty = (float) $line['qty'];
                $allocated = $allocations[$index];
                $landed = ((float) $line['line_cost'] + $allocated) / $qty;

                $purchase->items()->create([
                    'material_id' => $line['material_id'],
                    'qty' => $qty,
                    'line_cost' => $line['line_cost'],
                    'extra_allocated' => round($allocated, 2),
                    'landed_unit_cost' => round($landed, 4),
                ]);

                $material = Material::query()->lockForUpdate()->findOrFail($line['material_id']);
                $average = CostMath::weightedAverage($material->stock, $material->unit_cost, $qty, $landed);
                $this->stock->move($material->id, $qty, 'purchase', $purchase, $date, $average);
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
     * Delete a purchase and reverse its stock and expense. Unit cost is not rolled back.
     */
    public function delete(Purchase $purchase): void
    {
        DB::transaction(function () use ($purchase) {
            foreach ($purchase->items as $item) {
                $this->stock->move($item->material_id, -$item->qty, 'adjustment', $purchase);
            }

            Expense::query()->where('ref_type', $purchase->getMorphClass())->where('ref_id', $purchase->id)->delete();
            $purchase->delete();

            if ($purchase->receipt_path) {
                Storage::disk('local')->delete($purchase->receipt_path);
            }
        });
    }

    private function nextNumber(Carbon $date): string
    {
        $prefix = 'PUR-'.$date->year.'-';
        $last = Purchase::query()->where('purchase_no', 'like', $prefix.'%')->max('purchase_no');
        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
