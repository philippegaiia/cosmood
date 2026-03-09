<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('production_task_types', function (Blueprint $table): void {
            $table->boolean('is_capacity_consuming')->default(true)->after('is_active');
        });

        DB::table('production_task_types')
            ->whereIn('slug', ['curing', 'waiting-labels', 'quality-control'])
            ->update(['is_capacity_consuming' => false]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('production_task_types', function (Blueprint $table): void {
            $table->dropColumn('is_capacity_consuming');
        });
    }
};
