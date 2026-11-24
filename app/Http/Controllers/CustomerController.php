<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->string('q')->toString();

        return Inertia::render('customers/index', [
            'customers' => Customer::query()
                ->when($search, fn ($query) => $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%")))
                ->withCount('sales')->withSum('sales as balance', 'balance')
                ->orderBy('name')->paginate(30)->withQueryString(),
            'filters' => ['q' => $search],
        ]);
    }

    public function show(Customer $customer): Response
    {
        return Inertia::render('customers/show', [
            'customer' => $customer,
            'sales' => $customer->sales()->latest('sold_on')->get(['id', 'invoice_no', 'sold_on', 'total', 'balance', 'payment_status']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Customer::query()->create($this->validated($request));
        $this->toast('Customer added.');

        return back();
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $customer->update($this->validated($request, $customer));
        $this->toast('Customer updated.');

        return back();
    }

    public function destroy(Customer $customer): RedirectResponse
    {
        $customer->delete();
        $this->toast('Customer deleted. Their past sales were kept.');

        return to_route('customers.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Customer $customer = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30', Rule::unique('customers')->ignore($customer)],
            'address' => ['nullable', 'string'],
            'city' => ['nullable', 'string', 'max:255'],
            'type' => ['required', Rule::in(['retail', 'wholesale'])],
            'notes' => ['nullable', 'string'],
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $term = trim($request->string('q')->toString());

        if (mb_strlen($term) < 2) {
            return response()->json([]);
        }

        return response()->json(Customer::query()
            ->where('phone', 'like', "%{$term}%")->orWhere('name', 'like', "%{$term}%")
            ->orderBy('name')->limit(10)->get(['id', 'name', 'phone', 'address', 'city', 'type']));
    }
}
