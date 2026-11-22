<?php

use App\Models\Component;
use App\Models\Customer;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\User;
use App\Services\StockCheckService;
use Database\Seeders\CategorySeeder;
use Database\Seeders\SampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('every screen renders with sample data', function () {
    $this->seed([CategorySeeder::class, SampleDataSeeder::class]);
    $this->actingAs(User::factory()->create());

    $urls = [
        route('dashboard'), route('categories.index'), route('materials.index'), route('materials.show', Material::query()->first()),
        route('components.index'), route('components.show', Component::query()->first()), route('products.index'), route('products.show', Product::query()->first()), route('options.show', ProductOption::query()->first()),
        route('purchases.index'), route('purchases.create'), route('sales.index'), route('sales.create'), route('receivables.index'),
        route('customers.index'), route('wastage.index'), route('stock.index'), route('stock-checks.index'), route('expenses.index'), route('business.edit'),
        route('reports.export', 'sales'),
    ];

    foreach ($urls as $url) {
        $this->get($url)->assertOk();
    }

    $this->get(route('customers.show', Customer::factory()->create()))->assertOk();
    $this->get(route('stock-checks.show', app(StockCheckService::class)->start('all', '2026-11-30')))->assertOk();
});

test('guests cannot reach the ops screens', function () {
    $this->get(route('materials.index'))->assertRedirect(route('login'));
});
