<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('production_wave_stock_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_wave_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->decimal('reserved_quantity', 12, 3)->default(0);
            $table->timestamps();

            $table->unique(['production_wave_id', 'ingredient_id'], 'wave_stock_decisions_wave_ingredient_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('production_wave_stock_decisions');
    }
};
