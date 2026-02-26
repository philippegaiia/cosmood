<?php

use App\Enums\FormulaItemCalculationMode;
use App\Enums\Phases;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingredients', function (Blueprint $table): void {
            $table->string('base_unit', 8)->default('kg')->after('is_active');
        });

        Schema::table('formula_items', function (Blueprint $table): void {
            $table->string('calculation_mode', 32)
                ->default(FormulaItemCalculationMode::PercentOfOils->value)
                ->after('phase');
        });

        Schema::table('production_items', function (Blueprint $table): void {
            $table->string('calculation_mode', 32)
                ->default(FormulaItemCalculationMode::PercentOfOils->value)
                ->after('phase');
        });

        DB::table('formula_items')
            ->where('phase', Phases::Packaging->value)
            ->update(['calculation_mode' => FormulaItemCalculationMode::QuantityPerUnit->value]);

        DB::table('production_items')
            ->where('phase', Phases::Packaging->value)
            ->update(['calculation_mode' => FormulaItemCalculationMode::QuantityPerUnit->value]);
    }

    public function down(): void
    {
        Schema::table('production_items', function (Blueprint $table): void {
            $table->dropColumn('calculation_mode');
        });

        Schema::table('formula_items', function (Blueprint $table): void {
            $table->dropColumn('calculation_mode');
        });

        Schema::table('ingredients', function (Blueprint $table): void {
            $table->dropColumn('base_unit');
        });
    }
};
