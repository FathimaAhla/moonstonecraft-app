<?php

use App\Models\Component;
use App\Models\Customer;
use App\Models\Material;
use App\Models\ProductOption;
use App\Models\Sale;
use App\Models\User;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->plaster = Material::factory()->create(['stock' => 1000, 'unit_cost' => 0.5]);
    $this->bag = Material::factory()->create(['stock' => 100, 'unit_cost' => 10]);
    $packing = Component::factory()->create();
    $packing->stages()->create(['name' => 'Packing'])->lines()->create(['material_id' => $this->bag->id, 'qty' => 1]);

    $this->option = ProductOption::factory()->create(['retail_price' => 500, 'wholesale_price' => 400, 'courier_packing_component_id' => $packing->id, 'courier_fee_estimate' => 300]);
    $this->option->parts()->create(['material_id' => $this->plaster->id, 'qty' => 100]);
});

function saleData(array $overrides = []): array
{
    return array_replace_recursive([
        'sold_on' => '2026-11-20',
        'sale_type' => 'retail',
        'payment_method' => 'cash',
        'delivery_method' => 'pickup',
        'customer' => ['phone' => '0771234567', 'name' => 'Nimal'],
        'items' => [['product_option_id' => test()->option->id, 'qty' => 2, 'unit_price' => 500]],
    ], $overrides);
}

test('a retail cash sale is paid and deducts stock', function () {
    $this->actingAs(User::factory()->create())->post(route('sales.store'), saleData())->assertRedirect();

    $sale = Sale::query()->sole();

    expect($sale->invoice_no)->toBe('INV-2026-0001')
        ->and($sale->total)->toEqual(1000.0)
        ->and($sale->payment_status)->toBe('paid')
        ->and($sale->balance)->toEqual(0.0)
        ->and($sale->cost_total)->toEqual(100.0)
        ->and($this->plaster->fresh()->stock)->toEqual(800.0);
});

test('a wholesale sale with a deposit is partial, and a later payment makes it paid', function () {
    $sale = app(SaleService::class)->record(saleData(['sale_type' => 'wholesale', 'amount_paid' => 300, 'items' => [['unit_price' => 400]]]));

    expect($sale->payment_status)->toBe('partial')
        ->and($sale->balance)->toEqual(500.0);

    $this->actingAs(User::factory()->create())->post(route('sale-payments.store', $sale), [
        'paid_on' => '2026-11-25', 'amount' => 500, 'method' => 'bank',
    ])->assertRedirect();

    expect($sale->fresh()->payment_status)->toBe('paid')
        ->and($sale->fresh()->balance)->toEqual(0.0);
});

test('wholesale paid later is unpaid', function () {
    $sale = app(SaleService::class)->record(saleData(['sale_type' => 'wholesale', 'due_date' => '2026-12-20']));

    expect($sale->payment_status)->toBe('unpaid')
        ->and($sale->balance)->toEqual(1000.0);
});

test('cash on delivery stays pending until remitted, with the COD fee counted as settled', function () {
    $sale = app(SaleService::class)->record(saleData(['payment_method' => 'cod', 'delivery_method' => 'courier', 'courier_charge' => 350]));

    expect($sale->payment_status)->toBe('cod_pending')
        ->and($sale->total)->toEqual(1350.0)
        ->and($sale->courier_cost)->toEqual(310.0)
        ->and($this->bag->fresh()->stock)->toEqual(99.0);

    app(SaleService::class)->addPayment($sale, ['paid_on' => '2026-11-22', 'amount' => 1300, 'cod_fee' => 50, 'method' => 'cod_remit']);

    expect($sale->fresh()->payment_status)->toBe('paid');
});

test('deleting a sale restores stock including courier packing', function () {
    $sale = app(SaleService::class)->record(saleData(['payment_method' => 'cod', 'delivery_method' => 'courier']));

    $this->actingAs(User::factory()->create())->delete(route('sales.destroy', $sale))->assertRedirect();

    expect($this->plaster->fresh()->stock)->toEqual(1000.0)
        ->and($this->bag->fresh()->stock)->toEqual(100.0)
        ->and(Sale::query()->count())->toBe(0);
});

test('the same phone never creates a duplicate customer', function () {
    app(SaleService::class)->record(saleData());
    app(SaleService::class)->record(saleData(['customer' => ['name' => 'Nimal Perera', 'address' => 'Kandy']]));

    expect(Customer::query()->count())->toBe(1)
        ->and(Customer::query()->sole()->address)->toBe('Kandy');
});

test('the cost snapshot does not change after a material price edit', function () {
    $sale = app(SaleService::class)->record(saleData());
    $this->plaster->update(['unit_cost' => 5]);

    expect($sale->fresh()->cost_total)->toEqual(100.0)
        ->and($sale->items()->sole()->unit_cost)->toEqual(0.5 * 100);
});

test('customer search returns saved details', function () {
    Customer::factory()->create(['name' => 'Kamala', 'phone' => '0711111111']);

    $this->actingAs(User::factory()->create())->getJson(route('customers.search', ['q' => '07111']))
        ->assertOk()->assertJsonPath('0.name', 'Kamala');
});
