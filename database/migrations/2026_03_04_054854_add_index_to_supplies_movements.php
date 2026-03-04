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
        Schema::table('supplies_movements', function (Blueprint $table) {
            // Add composite index for efficient allocation queries
            $table->index(['supply_id', 'movement_type'], 'idx_supply_movement_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('supplies_movements', function (Blueprint $table) {
            $table->dropIndex('idx_supply_movement_type');
        });
    }
};
