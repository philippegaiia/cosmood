<?php

use App\Models\Supply\Ingredient;
use App\Models\Supply\Supplier;
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
        Schema::create('supplier_listings', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Supplier::class);
            $table->foreignIdFor(Ingredient::class);
            $table->string('name')->required();
            $table->string('code')->nullable()->unique();
            $table->string('supplier_code')->nullable();
            $table->string('pkg')->nullable();
            $table->string('unit_of_measure')->nullable()->default('kg');
            $table->decimal('unit_weight', 7, 2)->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->boolean('organic')->default(true);
            $table->boolean('fairtrade')->default(false);
            $table->boolean('cosmos')->default(false);
            $table->boolean('ecocert')->default(false);
            $table->longText('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('file_path')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_listings');
    }
};
