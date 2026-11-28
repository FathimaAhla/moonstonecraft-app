<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A figure set: designs from one kit. Its made-set stock is kept on `component_id`.
     */
    public function up(): void
    {
        Schema::create('figure_combos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('family', 50);
            $table->string('name');
            $table->unsignedTinyInteger('size');
            $table->string('signature')->unique();
            $table->foreignId('component_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('figure_combos');
    }
};
