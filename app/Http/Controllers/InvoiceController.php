<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\Setting;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\View\View;

class InvoiceController extends Controller
{
    public function receipt(Sale $sale): View
    {
        $sale->increment('print_count');
        $sale->forceFill(['printed_at' => now()])->save();

        return view('invoices.receipt', $this->data($sale));
    }

    public function invoice(Sale $sale): View
    {
        return view('invoices.a4', $this->data($sale));
    }

    public function pdf(Sale $sale): Response
    {
        return $this->makePdf($sale)->stream($sale->invoice_no.'.pdf');
    }

    public function download(Sale $sale): Response
    {
        return $this->makePdf($sale)->download($sale->invoice_no.'.pdf');
    }

    public function publicPdf(Sale $sale): Response
    {
        return $this->makePdf($sale)->stream($sale->invoice_no.'.pdf');
    }

    /**
     * @return array<string, mixed>
     */
    private function data(Sale $sale, bool $pdf = false): array
    {
        return ['sale' => $sale->load('items', 'customer', 'payments'), 's' => Setting::allValues(), 'pdf' => $pdf];
    }

    private function makePdf(Sale $sale): \Barryvdh\DomPDF\PDF
    {
        return Pdf::loadView('invoices.a4', $this->data($sale, true))->setPaper('a4');
    }
}
