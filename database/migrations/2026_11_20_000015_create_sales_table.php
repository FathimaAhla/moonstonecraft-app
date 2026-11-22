<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_no')->unique();
            $table->date('sold_on')->index();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sale_type', 20)->default('retail');
            $table->string('payment_method', 20)->default('cash');
            $table->string('delivery_method', 20)->default('pickup');
            $table->text('delivery_address')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('courier_charge', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->decimal('balance', 12, 2)->default(0);
            $table->string('payment_status', 20)->default('unpaid')->index();
            $table->date('due_date')->nullable();
            $table->decimal('cost_total', 12, 2)->default(0);
            $table->decimal('courier_cost', 12, 2)->default(0);
            $table->json('courier_usage')->nullable();
            $table->unsignedInteger('print_count')->default(0);
            $table->timestamp('printed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
