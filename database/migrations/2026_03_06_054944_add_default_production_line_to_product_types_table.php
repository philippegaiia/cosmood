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
        Schema::table('product_types', function (Blueprint $table) {
            $table->foreignIdFor(ProductionLine::class, 'default_production_line_id')
                ->nullable()
                ->after('qc_template_id')
                ->constrained('production_lines')
                ->nullOnDelete();

            $table->index('default_production_line_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_types', function (Blueprint $table) {
            $table->dropForeign(['default_production_line_id']);
            $table->dropIndex(['default_production_line_id']);
            $table->dropColumn('default_production_line_id');
        });
    }
};
