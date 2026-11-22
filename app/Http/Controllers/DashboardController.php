<?php

namespace App\Http\Controllers;

use App\Models\Purchase;
use App\Models\Sale;
use App\Services\ReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DashboardController extends Controller
{
    public function index(Request $request, ReportService $reports): Response
    {
        $month = $request->string('month')->toString() ?: now()->format('Y-m');
        $start = Carbon::parse($month.'-01')->startOfMonth();

        return Inertia::render('dashboard', [
            'month' => $month,
            'summary' => $reports->summary($start, $start->copy()->endOfMonth()),
        ]);
    }

    /**
     * Export sales, purchases or receivables as CSV.
     */
    public function export(Request $request, string $type): StreamedResponse
    {
        $month = $request->string('month')->toString() ?: now()->format('Y-m');
        $start = Carbon::parse($month.'-01')->startOfMonth();
        $range = [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()];

        [$header, $rows] = match ($type) {
            'sales' => [
                ['Invoice', 'Date', 'Customer', 'Phone', 'Type', 'Payment', 'Subtotal', 'Discount', 'Courier', 'Total', 'Paid', 'Balance', 'Status', 'Cost'],
                Sale::query()->with('customer')->whereBetween('sold_on', $range)->orderBy('sold_on')->get()->map(fn (Sale $s) => [
                    $s->invoice_no, $s->sold_on->toDateString(), $s->customer?->name, $s->customer?->phone, $s->sale_type, $s->payment_method,
                    $s->subtotal, $s->discount, $s->courier_charge, $s->total, $s->paid_amount, $s->balance, $s->payment_status, $s->cost_total + $s->courier_cost,
                ]),
            ],
            'purchases' => [
                ['Purchase', 'Date', 'Supplier', 'Items', 'Transport', 'Refreshment', 'Other', 'Grand total'],
                Purchase::query()->with('supplier')->whereBetween('purchased_on', $range)->orderBy('purchased_on')->get()->map(fn (Purchase $p) => [
                    $p->purchase_no, $p->purchased_on->toDateString(), $p->supplier?->name, $p->items_total, $p->transport_cost, $p->refreshment_cost, $p->other_cost, $p->grand_total,
                ]),
            ],
            'receivables' => [
                ['Invoice', 'Date', 'Due', 'Customer', 'Phone', 'Total', 'Paid', 'Balance', 'Status'],
                Sale::query()->with('customer')->where('balance', '>', 0)->orderBy('sold_on')->get()->map(fn (Sale $s) => [
                    $s->invoice_no, $s->sold_on->toDateString(), $s->due_date?->toDateString(), $s->customer?->name, $s->customer?->phone, $s->total, $s->paid_amount, $s->balance, $s->payment_status,
                ]),
            ],
            default => abort(404),
        };

        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $header);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, "{$type}-{$month}.csv", ['Content-Type' => 'text/csv']);
    }
}
