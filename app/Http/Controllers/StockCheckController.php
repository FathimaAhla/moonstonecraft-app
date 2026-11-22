<?php

namespace App\Http\Controllers;

use App\Models\StockCheck;
use App\Models\StockCheckLine;
use App\Services\StockCheckService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Stock counting sessions that find missing and extra stock.
 */
class StockCheckController extends Controller
{
    public function index(StockCheckService $service): Response
    {
        return Inertia::render('stock-checks/index', [
            'checks' => StockCheck::query()->with('lines')->latest('checked_on')->latest('id')->limit(100)->get()
                ->map(fn (StockCheck $check) => [
                    ...$check->only('id', 'checked_on', 'scope', 'status', 'notes', 'completed_at'),
                    ...$service->summary($check),
                ]),
            'scopes' => StockCheck::SCOPES,
        ]);
    }

    public function store(Request $request, StockCheckService $service): RedirectResponse
    {
        $data = $request->validate([
            'scope' => ['required', Rule::in(StockCheck::SCOPES)],
            'checked_on' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
        ]);
        $check = $service->start($data['scope'], $data['checked_on'], $data['notes'] ?? null);
        $this->toast('Stock check started. System quantities are frozen; type what you count.');

        return to_route('stock-checks.show', $check);
    }

    public function show(StockCheck $stockCheck, StockCheckService $service): Response
    {
        return Inertia::render('stock-checks/show', [
            'check' => $stockCheck->only('id', 'checked_on', 'scope', 'status', 'notes', 'completed_at'),
            'lines' => $stockCheck->lines->map(fn (StockCheckLine $line) => [
                ...$line->only('id', 'item_type', 'item_id', 'name', 'unit', 'system_qty', 'counted_qty', 'unit_cost', 'reason', 'note'),
                'difference' => $line->difference(),
            ]),
            'summary' => $service->summary($stockCheck),
            'reasons' => StockCheckService::LINE_REASONS,
        ]);
    }

    public function update(Request $request, StockCheck $stockCheck, StockCheckService $service): RedirectResponse
    {
        $data = $request->validate([
            'lines' => ['required', 'array'],
            'lines.*.counted_qty' => ['nullable', 'numeric', 'min:0'],
            'lines.*.reason' => ['nullable', Rule::in(StockCheckService::LINE_REASONS)],
            'lines.*.note' => ['nullable', 'string', 'max:255'],
        ]);

        if (! $stockCheck->isDraft()) {
            $this->toast('This stock check is completed and locked.', 'error');

            return back();
        }

        $service->saveCounts($stockCheck, $data['lines']);
        $this->toast('Counts saved.');

        return back();
    }

    public function complete(StockCheck $stockCheck, StockCheckService $service): RedirectResponse
    {
        if (! $stockCheck->isDraft()) {
            $this->toast('This stock check is already completed.', 'error');

            return back();
        }

        $service->complete($stockCheck);
        $this->toast('Stock check completed. Differences were written to the stock ledger.');

        return back();
    }

    public function destroy(StockCheck $stockCheck): RedirectResponse
    {
        if (! $stockCheck->isDraft()) {
            $this->toast('A completed stock check cannot be deleted.', 'error');

            return back();
        }

        $stockCheck->delete();
        $this->toast('Draft stock check deleted.');

        return to_route('stock-checks.index');
    }
}
