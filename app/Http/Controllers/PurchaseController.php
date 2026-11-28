<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePurchaseRequest;
use App\Models\Material;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Services\PurchaseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PurchaseController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('purchases/index', [
            'purchases' => Purchase::query()->with('supplier:id,name')->withCount('items')
                ->latest('purchased_on')->latest('id')->paginate(30),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('purchases/create', [
            'materials' => Material::query()->with('packSizes:id,material_id,name,size')->where('is_purchasable', true)->orderBy('name')->get(['id', 'name', 'unit', 'unit_cost', 'purchase_unit', 'purchase_conversion']),
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(StorePurchaseRequest $request, PurchaseService $service): RedirectResponse
    {
        $data = $request->validated();

        if (empty($data['supplier_id']) && filled($data['supplier_name'] ?? null)) {
            $data['supplier_id'] = Supplier::query()->firstOrCreate(['name' => trim($data['supplier_name'])])->id;
        }

        $lines = $data['lines'];
        unset($data['lines'], $data['supplier_name'], $data['receipt']);
        $purchase = $service->record($data, $lines);

        if ($request->hasFile('receipt')) {
            $purchase->update(['receipt_path' => $request->file('receipt')->store('receipts', 'local')]);
        }

        $this->toast("Purchase {$purchase->purchase_no} saved.");

        return to_route('purchases.show', $purchase);
    }

    public function edit(Purchase $purchase): Response
    {
        return Inertia::render('purchases/edit', [
            'purchase' => $purchase->load('items.material:id,name,unit'),
            'materials' => Material::query()->with('packSizes:id,material_id,name,size')->where('is_purchasable', true)->orderBy('name')->get(['id', 'name', 'unit', 'unit_cost', 'purchase_unit', 'purchase_conversion']),
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(StorePurchaseRequest $request, Purchase $purchase, PurchaseService $service): RedirectResponse
    {
        $data = $request->validated();

        if (empty($data['supplier_id']) && filled($data['supplier_name'] ?? null)) {
            $data['supplier_id'] = Supplier::query()->firstOrCreate(['name' => trim($data['supplier_name'])])->id;
        }

        $lines = $data['lines'];
        unset($data['lines'], $data['supplier_name'], $data['receipt']);
        $service->update($purchase, $data, $lines);

        if ($request->hasFile('receipt')) {
            $purchase->update(['receipt_path' => $request->file('receipt')->store('receipts', 'local')]);
        }

        $this->toast("Purchase {$purchase->purchase_no} updated.");

        return to_route('purchases.show', $purchase);
    }

    public function show(Purchase $purchase): Response
    {
        return Inertia::render('purchases/show', [
            'purchase' => $purchase->load('supplier', 'items.material:id,name,unit'),
        ]);
    }

    /**
     * Show the uploaded bill (kept private on the local disk).
     */
    public function receipt(Purchase $purchase): StreamedResponse
    {
        abort_unless($purchase->receipt_path && Storage::disk('local')->exists($purchase->receipt_path), 404);

        return Storage::disk('local')->response($purchase->receipt_path);
    }

    public function destroy(Purchase $purchase, PurchaseService $service): RedirectResponse
    {
        $service->delete($purchase);
        $this->toast('Purchase deleted and stock reversed. Material unit costs were not rolled back.', 'warning');

        return to_route('purchases.index');
    }
}
