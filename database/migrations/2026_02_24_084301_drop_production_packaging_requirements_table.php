<?php

use App\Models\Production\Production;
use App\Models\Production\ProductionWave;
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
        Schema::dropIfExists('production_packaging_requirements');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('production_packaging_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Production::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(ProductionWave::class)->nullable()->constrained()->nullOnDelete();
            $table->string('packaging_name');
            $table->string('packaging_code')->nullable();
            $table->integer('required_quantity')->default(0);
            $table->decimal('quantity_per_unit', 8, 4)->default(1);
            $table->foreignIdFor(Supplier::class)->nullable()->constrained()->nullOnDelete();
            $table->decimal('unit_cost', 10, 3)->nullable();
            $table->enum('status', ['not_ordered', 'ordered', 'confirmed', 'received', 'allocated'])->default('not_ordered');
            $table->integer('allocated_quantity')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }
};
