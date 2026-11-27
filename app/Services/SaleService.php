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
    public function __construct(private Costing $costing, private StockService $stock, private KitStockService $kits, private AssignmentService $assignments) {}

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
                'discount_type' => $data['discount_type'] ?? 'amount',
                'discount_value' => (float) ($data['discount'] ?? 0),
                'offer_name' => $data['offer_name'] ?? null,
                'courier_charge' => $data['delivery_method'] === 'courier' ? (float) ($data['courier_charge'] ?? 0) : 0,
                'courier_charge_payer' => $data['delivery_method'] === 'courier' ? ($data['courier_charge_payer'] ?? 'customer_on_delivery') : 'customer_on_delivery',
                'due_date' => $data['due_date'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            $subtotal = 0.0;
            $costTotal = 0.0;
            $courierOption = null;

            foreach ($data['items'] as $line) {
                $qty = (float) $line['qty'];
                $listPrice = (float) $line['unit_price'];
                $discountType = $line['discount_type'] ?? null;
                $discountValue = $discountType ? (float) ($line['discount_value'] ?? 0) : 0.0;
                $unitPrice = round($listPrice - self::discountAmount($discountType, $discountValue, $listPrice), 2);

                if (! empty($line['custom'])) {
                    $built = $this->customItem($line, $qty);
                    $option = null;
                } else {
                    $option = ProductOption::query()->with('product', 'courierPacking.stages.lines.material')->findOrFail($line['product_option_id']);
                    $built = $this->optionItem($option, $qty);
                }

                $item = $sale->items()->create([
                    'product_option_id' => $option?->id,
                    'code' => $option?->code ?? 'CUSTOM',
                    'name' => $option ? $option->product?->name.' - '.$option->name : ($line['name'] ?: 'Custom kit'),
                    'is_custom' => $option === null,
                    'qty' => $qty,
                    'list_price' => $listPrice,
                    'discount_type' => $discountValue > 0 ? $discountType : null,
                    'discount_value' => $discountValue,
                    'offer_name' => $line['offer_name'] ?? null,
                    'unit_price' => $unitPrice,
                    'unit_cost' => round($built['unit_cost'], 4),
                    'usage' => $built['usage'],
                    'contents' => $built['contents'],
                    'allocations' => $built['allocations'],
                ]);

                $this->kits->deductUsage($built['usage'], 'sale', $sale, $date);

                if ($option) {
                    $this->assignments->recordForItem($item, (array) ($line['assign'] ?? []));
                }

                $subtotal += $qty * $unitPrice;
                $costTotal += $qty * $item->fresh()->unit_cost;
                $courierOption ??= ($option && ($option->courier_packing_component_id || $option->courier_fee_estimate > 0)) ? $option : null;
            }

            $courierCost = 0.0;
            $courierUsage = [];

            if ($sale->delivery_method === 'courier' && $courierOption) {
                $courierCost = $this->costing->courierExtraCost($courierOption);
                $courierUsage = $courierOption->courierPacking
                    ? $this->kits->plan([['component_id' => $courierOption->courierPacking->id, 'qty' => 1]], 1)['usage']
                    : [];
                $this->kits->deductUsage($courierUsage, 'sale', $sale, $date);
            }

            $courierChargeToCustomer = $sale->courier_charge_payer === 'business' ? 0.0 : $sale->courier_charge;

            $sale->subtotal = round($subtotal, 2);
            $sale->discount = self::discountAmount($sale->discount_type, $sale->discount_value, $sale->subtotal);
            $sale->total = round($subtotal - $sale->discount + $courierChargeToCustomer, 2);
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
     * The Rs taken off a price: a percentage of it or a fixed amount, never more than the price itself.
     */
    public static function discountAmount(?string $type, float $value, float $price): float
    {
        $amount = match ($type) {
            'percent' => $price * $value / 100,
            'amount' => $value,
            default => 0.0,
        };

        return round(min(max($amount, 0), $price), 2);
    }

    /**
     * A catalogue kit: ready-packed kits first (no second deduction), then the rest from parts.
     * The contents snapshot is the option's recipe at this moment.
     *
     * @return array{unit_cost: float, usage: array<string, mixed>, contents: array<string, mixed>, allocations: list<array<string, mixed>>}
     */
    private function optionItem(ProductOption $option, float $qty): array
    {
        $parts = $this->kits->optionParts($option);
        $ready = $this->kits->allocateReady($option, $qty);
        $usage = $ready['ready'] > 0 ? StockService::bag(['options' => [$option->id => $ready['ready']]]) + ['prededucted' => []] : StockService::bag() + ['prededucted' => []];
        $unpacked = round($qty - $ready['ready'], 3);

        if ($unpacked > 0) {
            $usage = $this->kits->mergeUsages($usage, $this->kits->plan($parts, $unpacked)['usage']);
        }

        $snapshot = $this->costing->snapshot($parts, 1);

        return [
            'unit_cost' => $snapshot['cost'],
            'usage' => $usage,
            'contents' => ['custom' => false, 'packed_qty' => $ready['ready'], 'unpacked_qty' => $unpacked, 'lines' => $snapshot['lines']],
            'allocations' => $ready['allocations'],
        ];
    }

    /**
     * A custom kit built for this sale from chosen components and materials.
     *
     * @param  array<string, mixed>  $line
     * @return array{unit_cost: float, usage: array<string, mixed>, contents: array<string, mixed>, allocations: list<array<string, mixed>>}
     */
    private function customItem(array $line, float $qty): array
    {
        $parts = collect($line['parts'])->map(fn ($part) => [
            'component_id' => ($part['type'] ?? '') === 'component' ? $part['id'] : null,
            'material_id' => ($part['type'] ?? '') === 'material' ? $part['id'] : null,
            'qty' => $part['qty'],
        ])->all();
        $plan = $this->kits->plan($parts, $qty);
        $snapshot = $this->costing->snapshot($parts, 1);

        return [
            'unit_cost' => $snapshot['cost'],
            'usage' => $plan['usage'],
            'contents' => ['custom' => true, 'packed_qty' => 0, 'unpacked_qty' => $qty, 'lines' => $snapshot['lines']],
            'allocations' => [],
        ];
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
                $this->kits->restoreUsage($item->usage ?? [], 'adjustment', $sale);
                $this->assignments->restoreItem($item, $sale);
                $this->kits->returnAllocations($item->allocations ?? []);
            }

            $this->kits->restoreUsage($sale->courier_usage ?? [], 'adjustment', $sale);
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
