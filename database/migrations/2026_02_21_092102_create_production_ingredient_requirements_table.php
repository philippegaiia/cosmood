<?php

use App\Models\Production\Production;
use App\Models\Production\ProductionWave;
use App\Models\Supply\Ingredient;
use App\Models\Supply\SupplierListing;
use App\Models\Supply\Supply;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_ingredient_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Production::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(ProductionWave::class)->nullable()->constrained()->nullOnDelete();
            $table->foreignIdFor(Ingredient::class)->constrained()->cascadeOnDelete();
            $table->string('phase')->nullable();
            $table->foreignIdFor(SupplierListing::class)->nullable()->constrained()->nullOnDelete();
            $table->decimal('required_quantity', 10, 3)->default(0);
            $table->enum('status', ['not_ordered', 'ordered', 'confirmed', 'received', 'allocated'])->default('not_ordered');
            $table->decimal('allocated_quantity', 10, 3)->default(0);
            $table->foreignIdFor(Supply::class, 'allocated_from_supply_id')->nullable()->constrained('supplies')->nullOnDelete();
            $table->foreignIdFor(Production::class, 'fulfilled_by_masterbatch_id')->nullable()->constrained('productions')->nullOnDelete();
            $table->boolean('is_collapsed_in_ui')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_ingredient_requirements');
    }
};
