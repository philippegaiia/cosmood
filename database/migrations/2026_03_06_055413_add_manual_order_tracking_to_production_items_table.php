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
            $table->boolean('is_order_marked')
                ->default(false)
                ->after('procurement_status');

            $table->index(['production_id', 'is_order_marked']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('production_items', function (Blueprint $table) {
            $table->dropIndex(['production_id', 'is_order_marked']);
            $table->dropColumn('is_order_marked');
        });
    }
};
