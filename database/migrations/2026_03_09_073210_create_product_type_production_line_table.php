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
        Schema::create('product_type_production_line', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_type_id')
                ->constrained('product_types')
                ->cascadeOnDelete();
            $table->foreignId('production_line_id')
                ->constrained('production_lines')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['product_type_id', 'production_line_id']);
            $table->index('production_line_id');
        });

        DB::table('product_types')
            ->whereNotNull('default_production_line_id')
            ->orderBy('id')
            ->get(['id', 'default_production_line_id'])
            ->each(function (object $productType): void {
                DB::table('product_type_production_line')->insert([
                    'product_type_id' => $productType->id,
                    'production_line_id' => $productType->default_production_line_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_type_production_line');
    }
};
