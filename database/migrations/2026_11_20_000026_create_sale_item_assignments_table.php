<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The figure sets and paint sets given to a sold item, or packed into products ahead of a sale
     * (then `pack_record_id` is set and `qty_remaining` counts what sales can still take).
     */
    public function up(): void
    {
        Schema::create('sale_item_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_item_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('pack_record_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 10);
            $table->foreignId('figure_combo_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('component_id')->nullable()->constrained()->nullOnDelete();
            $table->string('label');
            $table->decimal('qty', 14, 3);
            $table->decimal('qty_remaining', 14, 3)->nullable();
            $table->decimal('unit_cost', 14, 4)->default(0);
            $table->json('usage')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_item_assignments');
    }
};
