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
        Schema::table('production_items', function (Blueprint $table) {
            $table->foreignId('split_from_item_id')
                ->nullable()
                ->after('sort')
                ->constrained('production_items')
                ->nullOnDelete();

            $table->foreignId('split_root_item_id')
                ->nullable()
                ->after('split_from_item_id')
                ->constrained('production_items')
                ->nullOnDelete();

            $table->index(['split_root_item_id', 'sort']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('production_items', function (Blueprint $table) {
            $table->dropForeign(['split_from_item_id']);
            $table->dropForeign(['split_root_item_id']);
            $table->dropColumn(['split_from_item_id', 'split_root_item_id']);
        });
    }
};
