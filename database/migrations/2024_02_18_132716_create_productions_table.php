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
        Schema::create('productions', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Product::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Formula::class)->constrained();
            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('productions');
            $table->boolean('is_masterbatch')->default(false);
            $table->string('slug')->nullable()->unique();
            $table->string('batch_number')->unique();
            $table->string('status')->default('planned');
            $table->date('production_date')->nullable();
            $table->date('ready_date')->nullable();
            $table->decimal('quantity_ingredients', 10, 2)->nullable();
            $table->unsignedMediumInteger('units_produced')->nullable();
            $table->boolean('organic')->default(true);
            $table->longText('notes')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('productions');
    }
};
