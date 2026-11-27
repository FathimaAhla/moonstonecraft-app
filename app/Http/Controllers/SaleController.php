<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSaleRequest;
use App\Models\Category;
use App\Models\Component;
use App\Models\Customer;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Setting;
use App\Services\AssignmentService;
use App\Services\KitCounts;
use App\Services\SaleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class SaleController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString() ?: null;

        return Inertia::render('sales/index', [
            'sales' => Sale::query()->with('customer:id,name,phone')
                ->when($status, fn ($query) => $query->where('payment_status', $status))
                ->latest('sold_on')->latest('id')->paginate(30)->withQueryString(),
            'filters' => ['status' => $status],
        ]);
    }

    public function create(AssignmentService $assignments): Response
    {
        return Inertia::render('sales/create', [
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name', 'phone', 'address', 'city', 'type']),
            'categories' => Category::query()->orderBy('sort')->orderBy('name')->get(['id', 'name']),
            'components' => Component::query()->where('active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'materials' => Material::query()->where('is_purchasable', true)->orderBy('name')->get(['id', 'name', 'unit']),
            'kits' => Product::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'category_id']),
            'options' => ProductOption::query()->with('product.category')->where('active', true)->orderBy('code')->get()
                ->map(fn (ProductOption $option) => [
                    'id' => $option->id,
                    'code' => $option->code,
                    'name' => $option->name,
                    'product_id' => $option->product_id,
                    'category_id' => $option->product?->category_id,
                    'retail_price' => $option->retail_price,
                    'wholesale_price' => $option->wholesale_price,
                    'picks' => KitCounts::picksFiguresAndPaint($option),
                    'figures_per_kit' => $option->figures_per_kit,
                    'paint_pots_per_kit' => $option->paint_pots_per_kit,
                    ...$assignments->pickable($option),
                ]),
        ]);
    }

    public function store(StoreSaleRequest $request, SaleService $service): RedirectResponse
    {
        try {
            $sale = $service->record($request->validated());
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['items' => $exception->getMessage()]);
        }
        $this->toast("Sale {$sale->invoice_no} saved.");

        if (Setting::value('default_print_action') === 'receipt') {
            return to_route('sales.show', ['sale' => $sale, 'print' => 1]);
        }

        if (Setting::value('default_print_action') === 'none') {
            return to_route('sales.create');
        }

        return to_route('sales.show', $sale);
    }

    public function show(Sale $sale, AssignmentService $assignments): Response
    {
        $sale->load('customer', 'items.option', 'items.assignments', 'payments');

        return Inertia::render('sales/show', [
            'sale' => $sale->makeHidden(['cost_total', 'courier_cost']),
            'assignments' => $sale->items->mapWithKeys(fn ($item) => [$item->id => $assignments->itemPanel($item)])->filter()->all(),
            'pickable' => $sale->items->pluck('option')->filter()->unique('id')->mapWithKeys(fn (ProductOption $option) => [$option->id => $assignments->pickable($option)])->all(),
            'profit' => [
                'cost' => round($sale->cost_total + $sale->courier_cost, 2),
                'profit' => round($sale->total - $sale->cost_total - $sale->courier_cost, 2),
            ],
            'publicLink' => URL::temporarySignedRoute('invoice.public', now()->addDays(30), $sale),
        ]);
    }

    public function destroy(Sale $sale, SaleService $service): RedirectResponse
    {
        $service->delete($sale);
        $this->toast('Sale deleted and stock restored.');

        return to_route('sales.index');
    }

    public function storePayment(Request $request, Sale $sale, SaleService $service): RedirectResponse
    {
        $service->addPayment($sale, $request->validate([
            'paid_on' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'cod_fee' => ['nullable', 'numeric', 'min:0'],
            'method' => ['required', Rule::in(['cash', 'bank', 'cod_remit'])],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]));
        $this->toast('Payment added.');

        return back();
    }

    public function destroyPayment(SalePayment $payment, SaleService $service): RedirectResponse
    {
        $service->deletePayment($payment);
        $this->toast('Payment removed.');

        return back();
    }

    public function receivables(): Response
    {
        $sales = Sale::query()->with('customer:id,name,phone,address')
            ->where('balance', '>', 0)->orderBy('due_date')->orderBy('sold_on')->get()
            ->map(fn (Sale $sale) => [
                ...$sale->only('id', 'invoice_no', 'total', 'paid_amount', 'balance', 'payment_status', 'payment_method'),
                'sold_on' => $sale->sold_on->toDateString(),
                'due_date' => $sale->due_date?->toDateString(),
                'days_overdue' => $sale->due_date && $sale->due_date->isPast() ? (int) $sale->due_date->diffInDays(today()) : 0,
                'customer' => $sale->customer,
            ]);

        return Inertia::render('receivables/index', [
            'groups' => $sales->groupBy(fn ($sale) => $sale['customer']['id'] ?? 0)->map(fn ($rows) => [
                'customer' => $rows->first()['customer'],
                'balance' => round($rows->sum('balance'), 2),
                'sales' => $rows->values(),
            ])->sortByDesc('balance')->values(),
            'total' => round($sales->sum('balance'), 2),
        ]);
    }
}
