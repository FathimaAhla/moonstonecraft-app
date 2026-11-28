<?php

use App\Models\Material;
use App\Models\ProductOption;
use App\Models\Sale;
use App\Models\User;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

beforeEach(function () {
    $material = Material::factory()->create(['stock' => 100, 'unit_cost' => 1.2345]);
    $option = ProductOption::factory()->create(['code' => 'PNT-NAM-006']);
    $option->parts()->create(['material_id' => $material->id, 'qty' => 10]);

    $this->sale = app(SaleService::class)->record([
        'sold_on' => '2026-11-20', 'sale_type' => 'retail', 'payment_method' => 'cod', 'delivery_method' => 'pickup',
        'customer' => ['phone' => '0770000000', 'name' => 'Sunil'],
        'items' => [['product_option_id' => $option->id, 'qty' => 1, 'unit_price' => 777]],
    ]);
    $this->actingAs(User::factory()->create());
});

test('receipt and A4 views render without costs', function () {
    $this->get(route('sales.receipt', $this->sale))->assertOk()
        ->assertSee('PNT-NAM-006')->assertSee('COLLECT')->assertDontSee('12.35');

    $this->get(route('sales.invoice', $this->sale))->assertOk()->assertSee('INVOICE')->assertDontSee('12.35');

    expect($this->sale->fresh()->print_count)->toBe(1);
});

test('pdf streams inline and downloads as attachment', function () {
    $this->get(route('sales.pdf', $this->sale))->assertOk()->assertHeader('Content-Type', 'application/pdf');

    $response = $this->get(route('sales.download', $this->sale))->assertOk();
    expect($response->headers->get('Content-Disposition'))->toContain('attachment')->toContain('INV-2026-0001.pdf');
});

test('signed customer link works and a tampered link is refused', function () {
    auth()->logout();
    $url = URL::temporarySignedRoute('invoice.public', now()->addDays(30), $this->sale);

    $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $tampered = preg_replace_callback('/signature=(\w)/', fn (array $match) => 'signature='.($match[1] === '0' ? '1' : '0'), $url);

    expect($tampered)->not->toBe($url);
    $this->get($tampered)->assertForbidden();
    $this->get(route('invoice.public', $this->sale))->assertForbidden();
});

test('a printed invoice keeps the saved values', function () {
    Sale::query()->whereKey($this->sale->id)->update(['notes' => 'changed']);
    $this->sale->items()->first()->option->update(['retail_price' => 1, 'code' => 'PNT-NAM-999']);

    $this->get(route('sales.receipt', $this->sale))->assertSee('PNT-NAM-006')->assertSee('777.00');
});
