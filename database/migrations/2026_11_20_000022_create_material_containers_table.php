<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Opened containers of a material (a paint bottle), to see how many kits each one made.
     */
    public function up(): void
    {
        Schema::create('material_containers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_id')->constrained()->restrictOnDelete();
            $table->string('label', 50);
            $table->decimal('size', 14, 3);
            $table->decimal('cost', 12, 2)->default(0);
            $table->date('opened_on');
            $table->date('finished_on')->nullable();
            $table->decimal('kits_made', 14, 3)->nullable();
            $table->string('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_containers');
    }
};
