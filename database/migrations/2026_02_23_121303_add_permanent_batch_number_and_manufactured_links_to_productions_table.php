<?php

use App\Models\Supply\Ingredient;
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
            $table->string('permanent_batch_number')->nullable()->after('batch_number');
            $table->foreignIdFor(Ingredient::class, 'produced_ingredient_id')
                ->nullable()
                ->after('masterbatch_lot_id')
                ->constrained('ingredients')
                ->nullOnDelete();

            $table->unique('permanent_batch_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('productions', function (Blueprint $table) {
            $table->dropUnique(['permanent_batch_number']);
            $table->dropConstrainedForeignId('produced_ingredient_id');
            $table->dropColumn('permanent_batch_number');
        });
    }
};
