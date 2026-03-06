<?php

use App\Models\Production\ProductionLine;
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
        Schema::table('productions', function (Blueprint $table) {
            $table->foreignIdFor(ProductionLine::class)
                ->nullable()
                ->after('production_wave_id')
                ->constrained('production_lines')
                ->nullOnDelete();

            $table->index(['production_wave_id', 'production_line_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('productions', function (Blueprint $table) {
            $table->dropIndex(['production_wave_id', 'production_line_id']);
            $table->dropForeign(['production_line_id']);
            $table->dropColumn('production_line_id');
        });
    }
};
