<?php

use App\Models\Expense;
use App\Models\Material;
use App\Models\Purchase;
use Database\Seeders\ExpenseSheetPurchaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(ExpenseSheetPurchaseSeeder::class);
});

test('the expense sheet adds up to the sheet grand total', function () {
    $nonPurchaseExpenses = Expense::query()->whereNull('ref_id')->sum('amount');

    expect(Purchase::query()->count())->toBe(12)
        ->and(round(Purchase::query()->sum('grand_total') + $nonPurchaseExpenses, 2))->toEqual(49100.0)
        ->and((float) Purchase::query()->where('notes', 'like', '%Master Colors%')->sole()->items_total)->toEqual(8180.0)
        ->and((float) Purchase::query()->where('notes', 'like', '%Daraz%')->sole()->grand_total)->toEqual(2779.0)
        ->and(Expense::query()->where('category', 'Purchase refreshment')->sum('amount'))->toEqual(1780);
});

test('purchases add stock in stock units', function () {
    expect(Material::query()->where('name', 'Plaster of Paris')->sole()->stock)->toEqual(17000.0)
        ->and(Material::query()->where('name', 'Empty paint pot')->sole()->stock)->toEqual(150.0)
        ->and(Material::query()->where('name', 'Paint brush')->sole()->stock)->toEqual(110.0);
});

test('running the seeder again does not duplicate purchases', function () {
    $this->seed(ExpenseSheetPurchaseSeeder::class);

    expect(Purchase::query()->count())->toBe(12)
        ->and(Expense::query()->count())->toBe(3);
});
