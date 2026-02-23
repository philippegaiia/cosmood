<?php

use App\Models\Production\ProductType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('batch_size_presets', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(ProductType::class)->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('batch_size', 10, 3);
            $table->integer('expected_units');
            $table->decimal('expected_waste_kg', 10, 3)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batch_size_presets');
    }
};
