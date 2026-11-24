<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Materials get independent purchasable / stock-tracked / cost-source settings.
     * `unit_cost` stays as the ACTIVE cost so existing costing keeps working unchanged.
     * Existing data keeps its meaning: stocked materials stay purchase-costed and tracked,
     * overheads stay fixed-cost (now "manual"), untracked and non-purchasable.
     */
    public function up(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->boolean('is_purchasable')->default(true)->after('type');
            $table->boolean('tracks_stock')->default(true)->after('is_purchasable');
            $table->string('purchase_unit', 20)->nullable()->after('unit');
            $table->decimal('purchase_conversion', 14, 4)->default(1)->after('purchase_unit');
            $table->decimal('purchase_unit_cost', 14, 4)->default(0)->after('unit_cost');
            $table->decimal('manual_unit_cost', 14, 4)->nullable()->after('purchase_unit_cost');
            $table->string('cost_source', 10)->default('purchase')->after('manual_unit_cost');
        });

        DB::table('materials')->where('type', 'overhead')->update([
            'is_purchasable' => false,
            'tracks_stock' => false,
            'cost_source' => 'manual',
            'manual_unit_cost' => DB::raw('unit_cost'),
            'purchase_unit_cost' => 0,
        ]);
        DB::table('materials')->where('type', '!=', 'overhead')->update(['purchase_unit_cost' => DB::raw('unit_cost')]);

        Schema::table('purchase_items', function (Blueprint $table) {
            $table->string('purchase_unit', 20)->nullable()->after('qty');
            $table->decimal('conversion_factor', 14, 4)->default(1)->after('purchase_unit');
            $table->decimal('converted_qty', 14, 3)->nullable()->after('conversion_factor');
        });
        DB::table('purchase_items')->update(['converted_qty' => DB::raw('qty')]);

        Schema::table('stage_lines', function (Blueprint $table) {
            $table->decimal('wastage_pct', 8, 2)->default(0)->after('qty');
        });

        Schema::table('components', function (Blueprint $table) {
            $table->decimal('prededucted_stock', 14, 3)->default(0)->after('stock');
        });
        DB::table('components')->update(['prededucted_stock' => DB::raw('CASE WHEN stock > 0 THEN stock ELSE 0 END')]);

        Schema::create('pack_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_option_id')->constrained()->restrictOnDelete();
            $table->date('packed_on');
            $table->decimal('qty', 14, 3);
            $table->decimal('qty_remaining', 14, 3);
            $table->decimal('unit_cost', 14, 4)->default(0);
            $table->boolean('is_legacy')->default(false);
            $table->json('contents')->nullable();
            $table->json('usage')->nullable();
            $table->json('usage_remaining')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();
        });

        $now = now();
        foreach (DB::table('product_options')->where('stock', '>', 0)->get(['id', 'stock']) as $option) {
            DB::table('pack_records')->insert([
                'product_option_id' => $option->id,
                'packed_on' => $now->toDateString(),
                'qty' => $option->stock,
                'qty_remaining' => $option->stock,
                'is_legacy' => true,
                'note' => 'Packed before contents were recorded. Original contents are unknown.',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Schema::table('sale_items', function (Blueprint $table) {
            $table->unsignedBigInteger('product_option_id')->nullable()->change();
            $table->boolean('is_custom')->default(false)->after('name');
            $table->json('contents')->nullable()->after('usage');
            $table->json('allocations')->nullable()->after('contents');
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', fn (Blueprint $table) => $table->dropColumn(['is_custom', 'contents', 'allocations']));
        Schema::dropIfExists('pack_records');
        Schema::table('components', fn (Blueprint $table) => $table->dropColumn('prededucted_stock'));
        Schema::table('stage_lines', fn (Blueprint $table) => $table->dropColumn('wastage_pct'));
        Schema::table('purchase_items', fn (Blueprint $table) => $table->dropColumn(['purchase_unit', 'conversion_factor', 'converted_qty']));
        Schema::table('materials', fn (Blueprint $table) => $table->dropColumn(['is_purchasable', 'tracks_stock', 'purchase_unit', 'purchase_conversion', 'purchase_unit_cost', 'manual_unit_cost', 'cost_source']));
    }
};
