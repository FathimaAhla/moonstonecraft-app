<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The stock ledger: each row moves a material, a component or a product.
     */
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('component_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('product_option_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('moved_on');
            $table->decimal('qty', 14, 3);
            $table->string('reason', 20);
            $table->nullableMorphs('ref');
            $table->decimal('unit_cost', 14, 4)->default(0);
            $table->string('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
