<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('figure_combo_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('figure_combo_id')->constrained()->cascadeOnDelete();
            $table->foreignId('component_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('qty');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('figure_combo_items');
    }
};
