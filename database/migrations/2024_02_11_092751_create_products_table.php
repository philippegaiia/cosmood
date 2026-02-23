<?php

use App\Models\Production\ProductCategory;
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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(ProductCategory::class)->constrained();
            $table->string('code')->nullable();
            $table->string('wp_code')->nullable();
            $table->string('name');
            $table->date('launch_date');
            $table->float('net_weight', 7, 3);
            $table->string('ean_code')->nullable();
            $table->text('description')->nullable();
            $table->smallInteger('is_active');
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
