<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Materials have independent purchasable / stock-tracked / cost-source settings.
     * `unit_cost` is the ACTIVE cost (from purchases or the manual cost).
     */
    public function up(): void
    {
        Schema::create('materials', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('kit_group', 20)->nullable();
            $table->string('colour', 7)->nullable();
            $table->string('unit', 20)->default('pc');
            $table->string('purchase_unit', 20)->nullable();
            $table->decimal('purchase_conversion', 14, 4)->default(1);
            $table->string('type', 20)->default('material');
            $table->boolean('is_purchasable')->default(true);
            $table->boolean('tracks_stock')->default(true);
            $table->boolean('track_by_container')->default(false);
            $table->decimal('stock', 14, 3)->default(0);
            $table->decimal('unit_cost', 14, 4)->default(0);
            $table->decimal('purchase_unit_cost', 14, 4)->default(0);
            $table->decimal('manual_unit_cost', 14, 4)->nullable();
            $table->string('cost_source', 10)->default('purchase');
            $table->decimal('reorder_level', 14, 3)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('materials');
    }
};
