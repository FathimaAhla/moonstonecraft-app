<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Parts you make (figures, paint pots, packing). Figure designs belong to a kit (product).
     */
    public function up(): void
    {
        Schema::create('components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('kit_group', 20)->nullable();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('figure_family', 50)->nullable();
            $table->json('pot_colours')->nullable();
            $table->decimal('yield_qty', 14, 3)->default(1);
            $table->decimal('wastage_pct', 8, 2)->default(0);
            $table->boolean('keeps_stock')->default(false);
            $table->boolean('uses_materials_when_made')->default(false);
            $table->decimal('stock', 14, 3)->default(0);
            $table->decimal('prededucted_stock', 14, 3)->default(0);
            $table->decimal('reorder_level', 14, 3)->default(0);
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('components');
    }
};
