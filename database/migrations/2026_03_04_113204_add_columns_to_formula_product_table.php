<?php

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
        Schema::table('formula_product', function (Blueprint $table) {
            $table->foreignId('formula_id')->nullable()->constrained()->cascadeOnDelete()->after('id');
            $table->foreignId('product_id')->nullable()->constrained()->cascadeOnDelete()->after('formula_id');
            $table->boolean('is_default')->default(false)->after('product_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('formula_product', function (Blueprint $table) {
            $table->dropForeign(['formula_id']);
            $table->dropForeign(['product_id']);
            $table->dropColumn(['formula_id', 'product_id', 'is_default']);
        });
    }
};
