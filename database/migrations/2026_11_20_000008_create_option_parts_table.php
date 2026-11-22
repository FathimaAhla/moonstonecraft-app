<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('option_parts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_option_id')->constrained()->cascadeOnDelete();
            $table->foreignId('material_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('component_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('qty', 14, 3);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('option_parts');
    }
};
