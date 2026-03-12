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
        Schema::table('production_items', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });

        Schema::table('production_tasks', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });

        Schema::table('supplier_order_items', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('production_items', function (Blueprint $table): void {
            $table->softDeletes();
        });

        Schema::table('production_tasks', function (Blueprint $table): void {
            $table->softDeletes();
        });

        Schema::table('supplier_order_items', function (Blueprint $table): void {
            $table->softDeletes();
        });
    }
};
