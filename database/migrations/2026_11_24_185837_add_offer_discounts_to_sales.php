<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Offer discounts: each item keeps its normal price, how its discount was given
     * (percent or Rs per unit) and an optional offer name. `unit_price` stays the price
     * actually charged. The whole-bill discount gets the same type, value and name;
     * `sales.discount` stays the Rs amount taken off.
     */
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->decimal('list_price', 12, 2)->default(0)->after('qty');
            $table->string('discount_type', 10)->nullable()->after('list_price');
            $table->decimal('discount_value', 12, 2)->default(0)->after('discount_type');
            $table->string('offer_name')->nullable()->after('discount_value');
        });

        DB::table('sale_items')->update(['list_price' => DB::raw('unit_price')]);

        Schema::table('sales', function (Blueprint $table) {
            $table->string('discount_type', 10)->default('amount')->after('subtotal');
            $table->decimal('discount_value', 12, 2)->default(0)->after('discount_type');
            $table->string('offer_name')->nullable()->after('discount');
        });

        DB::table('sales')->update(['discount_value' => DB::raw('discount')]);
    }

    public function down(): void
    {
        Schema::table('sale_items', fn (Blueprint $table) => $table->dropColumn(['list_price', 'discount_type', 'discount_value', 'offer_name']));
        Schema::table('sales', fn (Blueprint $table) => $table->dropColumn(['discount_type', 'discount_value', 'offer_name']));
    }
};
