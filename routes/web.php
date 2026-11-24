<?php

use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ComponentController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\MaterialController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductOptionController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\StockCheckController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WastageController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('reports/{type}.csv', [DashboardController::class, 'export'])->name('reports.export');

    Route::resource('categories', CategoryController::class)->only(['index', 'store', 'update', 'destroy']);

    Route::resource('materials', MaterialController::class)->except(['create', 'edit']);
    Route::post('materials/{material}/adjust', [MaterialController::class, 'adjust'])->name('materials.adjust');
    Route::patch('materials/{material}/cost', [MaterialController::class, 'cost'])->name('materials.cost');

    Route::resource('products', ProductController::class)->except(['create', 'edit']);
    Route::resource('components', ComponentController::class)->except(['create', 'edit']);
    Route::post('components/{component}/stages', [ComponentController::class, 'storeStage'])->name('stages.store');
    Route::post('components/{component}/copy', [ComponentController::class, 'copy'])->name('components.copy');
    Route::put('stages/{stage}', [ComponentController::class, 'updateStage'])->name('stages.update');
    Route::delete('stages/{stage}', [ComponentController::class, 'destroyStage'])->name('stages.destroy');
    Route::post('stages/{stage}/lines', [ComponentController::class, 'storeLine'])->name('stage-lines.store');
    Route::put('stage-lines/{line}', [ComponentController::class, 'updateLine'])->name('stage-lines.update');
    Route::delete('stage-lines/{line}', [ComponentController::class, 'destroyLine'])->name('stage-lines.destroy');

    Route::get('products/{product}/options/create', [ProductOptionController::class, 'create'])->name('options.create');
    Route::get('options/{option}/edit', [ProductOptionController::class, 'edit'])->name('options.edit');
    Route::post('products/{product}/options', [ProductOptionController::class, 'store'])->name('options.store');
    Route::get('options/{option}', [ProductOptionController::class, 'show'])->name('options.show');
    Route::put('options/{option}', [ProductOptionController::class, 'update'])->name('options.update');
    Route::delete('options/{option}', [ProductOptionController::class, 'destroy'])->name('options.destroy');
    Route::post('options/{option}/parts', [ProductOptionController::class, 'storePart'])->name('option-parts.store');
    Route::delete('option-parts/{part}', [ProductOptionController::class, 'destroyPart'])->name('option-parts.destroy');

    Route::resource('purchases', PurchaseController::class)->only(['index', 'create', 'store', 'show', 'destroy']);
    Route::get('purchases/{purchase}/receipt', [PurchaseController::class, 'receipt'])->name('purchases.receipt');

    Route::get('stock', [StockController::class, 'index'])->name('stock.index');
    Route::post('components/{component}/make', [StockController::class, 'make'])->name('stock.make');
    Route::post('components/{component}/adjust', [StockController::class, 'adjustComponent'])->name('stock.adjust-component');
    Route::post('options/{option}/pack', [StockController::class, 'pack'])->name('stock.pack');
    Route::post('options/{option}/unpack', [StockController::class, 'unpack'])->name('stock.unpack');
    Route::post('options/{option}/adjust', [StockController::class, 'adjustOption'])->name('stock.adjust-option');

    Route::resource('stock-checks', StockCheckController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
    Route::post('stock-checks/{stock_check}/complete', [StockCheckController::class, 'complete'])->name('stock-checks.complete');

    Route::get('customers/search', [CustomerController::class, 'search'])->name('customers.search');
    Route::resource('customers', CustomerController::class)->only(['index', 'show', 'store', 'update', 'destroy']);

    Route::get('receivables', [SaleController::class, 'receivables'])->name('receivables.index');
    Route::resource('sales', SaleController::class)->only(['index', 'create', 'store', 'show', 'destroy']);
    Route::post('sales/{sale}/payments', [SaleController::class, 'storePayment'])->name('sale-payments.store');
    Route::delete('sale-payments/{payment}', [SaleController::class, 'destroyPayment'])->name('sale-payments.destroy');

    Route::get('sales/{sale}/receipt', [InvoiceController::class, 'receipt'])->name('sales.receipt');
    Route::get('sales/{sale}/invoice', [InvoiceController::class, 'invoice'])->name('sales.invoice');
    Route::get('sales/{sale}/invoice.pdf', [InvoiceController::class, 'pdf'])->name('sales.pdf');
    Route::get('sales/{sale}/invoice/download', [InvoiceController::class, 'download'])->name('sales.download');

    Route::resource('wastage', WastageController::class)->only(['index', 'store', 'destroy'])->parameters(['wastage' => 'wastage']);
    Route::resource('expenses', ExpenseController::class)->only(['index', 'store', 'destroy']);

    Route::middleware('admin')->group(function () {
        Route::resource('users', UserController::class)->only(['index', 'store', 'update', 'destroy']);
    });
});

Route::get('i/{sale}', [InvoiceController::class, 'publicPdf'])->name('invoice.public')->middleware('signed');

require __DIR__.'/settings.php';
