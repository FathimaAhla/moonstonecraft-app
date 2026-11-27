<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `list_price` is the normal price; `unit_price` is the price actually charged after the item's discount.
     */
    public function up(): void
    {
        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_option_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('code');
            $table->string('name');
            $table->boolean('is_custom')->default(false);
            $table->decimal('qty', 14, 3);
            $table->decimal('list_price', 12, 2)->default(0);
            $table->string('discount_type', 10)->nullable();
            $table->decimal('discount_value', 12, 2)->default(0);
            $table->string('offer_name')->nullable();
            $table->decimal('unit_price', 12, 2);
            $table->decimal('unit_cost', 14, 4);
            $table->json('usage')->nullable();
            $table->json('contents')->nullable();
            $table->json('allocations')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_items');
    }
};
