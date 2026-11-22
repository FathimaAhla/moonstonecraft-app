<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Parts you make (figures, paint pots) and packed kits get their own stock.
     * The stock ledger can now point at a material, a component or a kit option.
     */
    public function up(): void
    {
        Schema::table('components', function (Blueprint $table) {
            $table->boolean('keeps_stock')->default(false)->after('wastage_pct');
            $table->decimal('stock', 14, 3)->default(0)->after('keeps_stock');
            $table->decimal('reorder_level', 14, 3)->default(0)->after('stock');
        });

        Schema::table('product_options', function (Blueprint $table) {
            $table->decimal('stock', 14, 3)->default(0)->after('courier_fee_estimate');
            $table->decimal('reorder_level', 14, 3)->default(0)->after('stock');
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->unsignedBigInteger('material_id')->nullable()->change();
            $table->foreignId('component_id')->nullable()->after('material_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_option_id')->nullable()->after('component_id')->constrained()->restrictOnDelete();
            $table->string('note')->nullable()->after('unit_cost');
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->string('receipt_path')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('purchases', fn (Blueprint $table) => $table->dropColumn('receipt_path'));

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_option_id');
            $table->dropConstrainedForeignId('component_id');
            $table->dropColumn('note');
        });

        Schema::table('product_options', fn (Blueprint $table) => $table->dropColumn(['stock', 'reorder_level']));
        Schema::table('components', fn (Blueprint $table) => $table->dropColumn(['keeps_stock', 'stock', 'reorder_level']));
    }
};
