<?php

use App\Models\Production\ProductionWave;
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
        Schema::table('supplier_orders', function (Blueprint $table) {
            $table->foreignIdFor(ProductionWave::class)
                ->nullable()
                ->after('supplier_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('supplier_orders', function (Blueprint $table) {
            $table->dropForeign(['production_wave_id']);
            $table->dropColumn('production_wave_id');
        });
    }
};
