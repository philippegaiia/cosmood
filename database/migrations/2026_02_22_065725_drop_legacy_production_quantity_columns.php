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
        DB::table('productions')
            ->whereNull('actual_units')
            ->whereNotNull('units_produced')
            ->update([
                'actual_units' => DB::raw('units_produced'),
            ]);

        Schema::table('productions', function (Blueprint $table) {
            $table->dropColumn(['quantity_ingredients', 'units_produced']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('productions', function (Blueprint $table) {
            $table->decimal('quantity_ingredients', 10, 2)->nullable();
            $table->unsignedMediumInteger('units_produced')->nullable();
        });
    }
};
