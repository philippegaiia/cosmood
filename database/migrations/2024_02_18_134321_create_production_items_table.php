<?php

use App\Models\Production\Production;
use App\Models\Supply\Ingredient;
use App\Models\Supply\SupplierListing;
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
        Schema::create('production_items', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Production::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Ingredient::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(SupplierListing::class)->constrained()->cascadeOnDelete()->nullable();
            $table->decimal('percentage_of_oils', 5, 2);
            $table->string('phase');
            $table->boolean('organic')->default(true);
            $table->boolean('is_supplied')->default(false);
            $table->integer('sort');
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('production_items');
    }
};
