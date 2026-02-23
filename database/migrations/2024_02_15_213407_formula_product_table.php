<?php

use App\Models\Production\Formula;
use App\Models\Production\Product;
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
        Schema::create('formula_product', function (Blueprint $table) {
            $table->id();
            // $table->foreignIdFor(Formula::class)->constrained();
            // $table->foreignIdFor(Product::class)->constrained();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('formula_product');
    }
};
