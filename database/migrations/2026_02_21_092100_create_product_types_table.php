<?php

use App\Models\Production\ProductCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->foreignIdFor(ProductCategory::class)->nullable()->constrained()->nullOnDelete();
            $table->enum('sizing_mode', ['oil_weight', 'final_mass', 'units'])->default('oil_weight');
            $table->decimal('default_batch_size', 10, 3)->default(0);
            $table->integer('expected_units_output')->default(0);
            $table->decimal('expected_waste_kg', 10, 3)->nullable();
            $table->decimal('unit_fill_size', 10, 3)->nullable();
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_types');
    }
};
