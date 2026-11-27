<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each time products are packed: how many, what went in, and how many are left to sell.
     */
    public function up(): void
    {
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
    }

    public function down(): void
    {
        Schema::dropIfExists('pack_records');
    }
};
