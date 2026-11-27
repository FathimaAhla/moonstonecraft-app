<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wastage_logs', function (Blueprint $table) {
            $table->id();
            $table->date('logged_on')->index();
            $table->string('scope', 20);
            $table->foreignId('material_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('component_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('qty', 14, 3)->nullable();
            $table->decimal('cost', 12, 2);
            $table->json('usage')->nullable();
            $table->string('reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wastage_logs');
    }
};
