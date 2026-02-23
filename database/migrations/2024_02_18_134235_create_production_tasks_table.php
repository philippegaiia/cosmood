<?php

use App\Models\Production\Production;
use App\Models\Production\ProductionTaskType;
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
        Schema::create('production_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Production::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(ProductionTaskType::class)->constrained()->cascadeOnDelete();
            $table->string('slug')->nullable();
            $table->date('date')->default(now());
            $table->mediumText('notes')->nullable();
            $table->boolean('is_finished')->default(false);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('production_tasks');
    }
};
