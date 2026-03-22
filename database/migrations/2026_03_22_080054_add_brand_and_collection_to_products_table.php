<?php

use App\Models\Production\Brand;
use App\Models\Production\Collection;
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
        Schema::table('products', function (Blueprint $table) {
            $table->foreignIdFor(Brand::class)
                ->nullable()
                ->after('product_type_id')
                ->constrained()
                ->nullOnDelete();

            $table->foreignIdFor(Collection::class)
                ->nullable()
                ->after('brand_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('collection_id');
            $table->dropConstrainedForeignId('brand_id');
        });
    }
};
