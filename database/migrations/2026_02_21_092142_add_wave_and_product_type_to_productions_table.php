<?php

use App\Models\Production\BatchSizePreset;
use App\Models\Production\ProductionWave;
use App\Models\Production\ProductType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productions', function (Blueprint $table) {
            $table->foreignIdFor(ProductionWave::class)->nullable()->after('id')->constrained()->nullOnDelete();
            $table->foreignIdFor(ProductType::class)->nullable()->after('formula_id')->constrained()->nullOnDelete();
            $table->foreignIdFor(BatchSizePreset::class)->nullable()->after('product_type_id')->constrained()->nullOnDelete();
            $table->enum('sizing_mode', ['oil_weight', 'final_mass', 'units'])->nullable()->after('is_masterbatch');
            $table->decimal('planned_quantity', 10, 3)->nullable()->after('sizing_mode');
            $table->integer('expected_units')->nullable()->after('planned_quantity');
            $table->decimal('expected_waste_kg', 10, 3)->nullable()->after('expected_units');
            $table->integer('actual_units')->nullable()->after('expected_waste_kg');
            $table->string('replaces_phase')->nullable()->after('actual_units');
            $table->foreignId('masterbatch_lot_id')->nullable()->after('replaces_phase')->constrained('productions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('productions', function (Blueprint $table) {
            $table->dropForeign(['production_wave_id']);
            $table->dropForeign(['product_type_id']);
            $table->dropForeign(['batch_size_preset_id']);
            $table->dropForeign(['masterbatch_lot_id']);
            $table->dropColumn([
                'production_wave_id',
                'product_type_id',
                'batch_size_preset_id',
                'sizing_mode',
                'planned_quantity',
                'expected_units',
                'expected_waste_kg',
                'actual_units',
                'replaces_phase',
                'masterbatch_lot_id',
            ]);
        });
    }
};
