<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_items', function (Blueprint $table) {
            $table->decimal('required_quantity', 10, 3)->default(0)->after('calculation_mode');
            $table->string('procurement_status', 20)->default('not_ordered')->after('required_quantity');
            $table->string('allocation_status', 20)->default('unassigned')->after('procurement_status');
        });

        Schema::table('production_items', function (Blueprint $table) {
            $table->index(['production_id', 'procurement_status']);
            $table->index(['production_id', 'allocation_status']);
        });
    }

    public function down(): void
    {
        Schema::table('production_items', function (Blueprint $table) {
            $table->dropIndex(['production_id', 'procurement_status']);
            $table->dropIndex(['production_id', 'allocation_status']);
            $table->dropColumn(['required_quantity', 'procurement_status', 'allocation_status']);
        });
    }
};
