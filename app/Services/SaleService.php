<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\ProductOption;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SaleService
{
    public function __construct(private Costing $costing, private StockService $stock, private KitStockService $kits) {}

    /**
     * Record a sale: customer, cost snapshot, stock deduction, payments and status.
     *
     * @param  array<string, mixed>  $data
     */
    public function record(array $data): Sale
    {
        return DB::transaction(function () use ($data) {
            $date = Carbon::parse($data['sold_on']);
            $customer = $this->resolveCustomer($data['customer'] ?? [], $data['sale_type']);

            $sale = Sale::query()->create([
                'invoice_no' => $this->nextInvoiceNumber($date),
                'sold_on' => $date->toDateString(),
                'customer_id' => $customer?->id,
                'sale_type' => $data['sale_type'],
                'payment_method' => $data['payment_method'],
                'delivery_method' => $data['delivery_method'],
                'delivery_address' => $data['delivery_address'] ?? $customer?->address,
                'discount' => (float) ($data['discount'] ?? 0),
                'courier_charge' => $data['delivery_method'] === 'courier' ? (float) ($data['courier_charge'] ?? 0) : 0,
                'due_date' => $data['due_date'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            $subtotal = 0.0;
            $costTotal = 0.0;
            $courierOption = null;

            foreach ($data['items'] as $line) {
                $option = ProductOption::query()->with('product', 'parts.material', 'parts.component.stages.lines.material', 'courierPacking.stages.lines.material')
                    ->findOrFail($line['product_option_id']);
                $qty = (float) $line['qty'];
                $unitPrice = (float) $line['unit_price'];
                $unitCost = $this->costing->optionCost($option);
                $usage = $this->kits->saleUsage($option, $qty);

                $sale->items()->create([
                    'product_option_id' => $option->id,
                    'code' => $option->code,
                    'name' => $option->product?->name.' - '.$option->name,
                    'qty' => $qty,
                    'unit_price' => $unitPrice,
                    'unit_cost' => round($unitCost, 4),
                    'usage' => $usage,
                ]);

                $this->stock->deduct($usage, 'sale', $sale, $date);
                $subtotal += $qty * $unitPrice;
                $costTotal += $qty * $unitCost;
                $courierOption ??= ($option->courier_packing_component_id || $option->courier_fee_estimate > 0) ? $option : null;
            }

            $courierCost = 0.0;
            $courierUsage = [];

            if ($sale->delivery_method === 'courier' && $courierOption) {
                $courierCost = $this->costing->courierExtraCost($courierOption);
                $courierUsage = $courierOption->courierPacking ? $this->costing->componentStockUsage($courierOption->courierPacking, 1) : [];
                $this->stock->deduct($courierUsage, 'sale', $sale, $date);
            }

            $sale->subtotal = round($subtotal, 2);
            $sale->total = round($subtotal - $sale->discount + $sale->courier_charge, 2);
            $sale->cost_total = round($costTotal, 2);
            $sale->courier_cost = round($courierCost, 2);
            $sale->courier_usage = $courierUsage;
            $sale->save();

            if ($sale->payment_method !== 'cod') {
                $amountPaid = $data['amount_paid'] ?? null;
                $amountPaid = $amountPaid === null || $amountPaid === ''
                    ? ($sale->sale_type === 'retail' ? $sale->total : 0)
                    : min((float) $amountPaid, $sale->total);

                if ($amountPaid > 0) {
                    $sale->payments()->create([
                        'paid_on' => $date->toDateString(),
                        'amount' => $amountPaid,
                        'method' => $sale->payment_method,
                    ]);
                }
            }

            $this->refreshStatus($sale);

            return $sale;
        });
    }

    /**
     * @param  array{paid_on: string, amount: float, method: string, cod_fee?: float|null, reference?: string|null, notes?: string|null}  $data
     */
    public function addPayment(Sale $sale, array $data): SalePayment
    {
        return DB::transaction(function () use ($sale, $data) {
            $payment = $sale->payments()->create([
                'paid_on' => $data['paid_on'],
                'amount' => $data['amount'],
                'cod_fee' => $data['cod_fee'] ?? 0,
                'method' => $data['method'],
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
            $this->refreshStatus($sale);

            return $payment;
        });
    }

    public function deletePayment(SalePayment $payment): void
    {
        DB::transaction(function () use ($payment) {
            $sale = $payment->sale;
            $payment->delete();
            $this->refreshStatus($sale);
        });
    }

    /**
     * A COD fee deducted by the courier counts as settled, so it is added to the paid amount.
     */
    public function refreshStatus(Sale $sale): void
    {
        $paid = round((float) $sale->payments()->sum(DB::raw('amount + cod_fee')), 2);
        $sale->paid_amount = $paid;
        $sale->balance = round(max(0, $sale->total - $paid), 2);
        $sale->payment_status = match (true) {
            $paid >= $sale->total - 0.005 => 'paid',
            $paid > 0 => 'partial',
            $sale->payment_method === 'cod' => 'cod_pending',
            default => 'unpaid',
        };
        $sale->save();
    }

    /**
     * Delete a sale and restore the stock it used.
     */
    public function delete(Sale $sale): void
    {
        DB::transaction(function () use ($sale) {
            foreach ($sale->items as $item) {
                $this->stock->restore($item->usage ?? [], 'adjustment', $sale);
            }

            $this->stock->restore($sale->courier_usage ?? [], 'adjustment', $sale);
            $sale->delete();
        });
    }

    /**
     * Find the customer by phone, updating saved details, or create a new one.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveCustomer(array $data, string $saleType): ?Customer
    {
        $phone = trim((string) ($data['phone'] ?? ''));

        if ($phone === '') {
            return null;
        }

        $customer = Customer::query()->firstOrNew(['phone' => $phone]);
        $customer->fill(array_filter([
            'name' => $data['name'] ?? null,
            'address' => $data['address'] ?? null,
            'city' => $data['city'] ?? null,
        ], fn ($value) => filled($value)));
        $customer->name ??= $phone;

        if (! $customer->exists) {
            $customer->type = $saleType;
        }

        $customer->save();

        return $customer;
    }

    private function nextInvoiceNumber(Carbon $date): string
    {
        $prefix = Setting::value('invoice_prefix').'-'.$date->year.'-';
        $last = Sale::query()->where('invoice_no', 'like', $prefix.'%')->lockForUpdate()->max('invoice_no');
        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
