<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A stock check freezes what the system thinks you have, you type what you counted,
     * and completing it writes the differences to the ledger as missing or found.
     */
    public function up(): void
    {
        Schema::create('stock_checks', function (Blueprint $table) {
            $table->id();
            $table->date('checked_on');
            $table->string('scope', 20);
            $table->string('status', 20)->default('draft');
            $table->text('notes')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('stock_check_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_check_id')->constrained()->cascadeOnDelete();
            $table->string('item_type', 20);
            $table->unsignedBigInteger('item_id');
            $table->string('name');
            $table->string('unit', 20)->default('pc');
            $table->decimal('system_qty', 14, 3);
            $table->decimal('counted_qty', 14, 3)->nullable();
            $table->decimal('unit_cost', 14, 4)->default(0);
            $table->string('reason', 20)->nullable();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['item_type', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_check_lines');
        Schema::dropIfExists('stock_checks');
    }
};
