<?php

use App\Models\Customer;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('a customer can be created, edited and deleted', function () {
    $this->post(route('customers.store'), ['name' => 'Nimal', 'phone' => '0771111111', 'type' => 'retail'])->assertRedirect();

    $customer = Customer::query()->sole();
    expect($customer->name)->toBe('Nimal');

    $this->put(route('customers.update', $customer), ['name' => 'Nimal P', 'phone' => '0771111111', 'type' => 'wholesale'])->assertRedirect();
    expect($customer->fresh()->type)->toBe('wholesale');

    $this->delete(route('customers.destroy', $customer))->assertRedirect(route('customers.index'));
    expect(Customer::query()->count())->toBe(0);
});

test('a duplicate phone is rejected', function () {
    Customer::factory()->create(['phone' => '0772222222']);

    $this->post(route('customers.store'), ['name' => 'Other', 'phone' => '0772222222', 'type' => 'retail'])->assertSessionHasErrors('phone');
});

test('deleting a customer keeps their sales', function () {
    $customer = Customer::factory()->create();
    $sale = Sale::query()->create(['invoice_no' => 'INV-1', 'sold_on' => '2026-11-20', 'customer_id' => $customer->id]);

    $this->delete(route('customers.destroy', $customer));

    expect($sale->fresh()->customer_id)->toBeNull();
});

test('the new sale page lists customers, categories, kits and options', function () {
    Customer::factory()->create();

    $this->get(route('sales.create'))->assertOk()->assertInertia(fn ($page) => $page
        ->component('sales/create')->has('customers', 1)->has('categories')->has('kits')->has('options'));
});
