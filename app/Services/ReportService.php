<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Material;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\WastageLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReportService
{
    /**
     * Profit summary for a date range. Courier charged to the customer counts as income,
     * and the real courier cost counts as cost.
     *
     * @return array<string, mixed>
     */
    public function summary(Carbon $from, Carbon $to): array
    {
        $range = [$from->toDateString(), $to->toDateString()];
        $sales = Sale::query()->whereBetween('sold_on', $range);

        $income = (float) (clone $sales)->sum('total');
        $cost = (float) (clone $sales)->sum(DB::raw('cost_total + courier_cost'));

        $wastage = WastageLog::query()->whereBetween('logged_on', $range)
            ->selectRaw('scope, SUM(cost) as total')->groupBy('scope')->pluck('total', 'scope')
            ->map(fn ($value) => (float) $value);

        $expenses = (float) Expense::query()->whereBetween('spent_on', $range)->sum('amount');
        $codFees = (float) SalePayment::query()->whereBetween('paid_on', $range)->sum('cod_fee');
        $wastageTotal = (float) $wastage->sum();

        return [
            'from' => $range[0],
            'to' => $range[1],
            'sales_count' => (clone $sales)->count(),
            'income' => round($income, 2),
            'cost' => round($cost, 2),
            'gross_profit' => round($income - $cost, 2),
            'wastage' => [
                'material' => $wastage['material'] ?? 0,
                'product' => $wastage['product'] ?? 0,
                'business' => $wastage['business'] ?? 0,
                'total' => round($wastageTotal, 2),
            ],
            'expenses' => round($expenses + $codFees, 2),
            'net_profit' => round($income - $cost - $wastageTotal - $expenses - $codFees, 2),
            'cash_received' => round((float) SalePayment::query()->whereBetween('paid_on', $range)->sum('amount'), 2),
            'receivables' => round((float) Sale::query()->where('balance', '>', 0)->sum('balance'), 2),
            'purchases_total' => round((float) Purchase::query()->whereBetween('purchased_on', $range)->sum('grand_total'), 2),
            'low_stock' => Material::query()->where('type', 'material')->whereColumn('stock', '<=', 'reorder_level')
                ->orderBy('name')->get(['id', 'name', 'unit', 'stock', 'reorder_level']),
            'top_options' => $this->profitByOption($from, $to)->take(5)->values(),
            'by_category' => $this->profitByCategory($from, $to),
            'wastage_by_product' => WastageLog::query()->where('scope', 'product')->whereBetween('logged_on', $range)
                ->join('components', 'components.id', '=', 'wastage_logs.component_id')
                ->selectRaw('components.code, components.name, SUM(wastage_logs.qty) as qty, SUM(wastage_logs.cost) as cost')
                ->groupBy('components.code', 'components.name')->orderByDesc('cost')->get(),
        ];
    }

    /**
     * @return Collection<int, object>
     */
    public function profitByOption(Carbon $from, Carbon $to)
    {
        return SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->whereBetween('sales.sold_on', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('sale_items.code, MAX(sale_items.name) as name, SUM(sale_items.qty) as qty,
                SUM(sale_items.qty * sale_items.unit_price) as revenue,
                SUM(sale_items.qty * (sale_items.unit_price - sale_items.unit_cost)) as profit')
            ->groupBy('sale_items.code')
            ->orderByDesc('profit')
            ->get();
    }

    /**
     * @return Collection<int, object>
     */
    public function profitByCategory(Carbon $from, Carbon $to)
    {
        return SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('product_options', 'product_options.id', '=', 'sale_items.product_option_id')
            ->join('products', 'products.id', '=', 'product_options.product_id')
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->whereBetween('sales.sold_on', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('categories.name, SUM(sale_items.qty * sale_items.unit_price) as revenue,
                SUM(sale_items.qty * (sale_items.unit_price - sale_items.unit_cost)) as profit')
            ->groupBy('categories.name')
            ->orderByDesc('profit')
            ->get();
    }
}
