<?php

use App\Models\Production\QcTemplate;
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
        Schema::create('qc_template_items', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(QcTemplate::class)->constrained()->cascadeOnDelete();
            $table->string('code')->nullable();
            $table->string('label');
            $table->string('input_type')->default('number');
            $table->string('unit')->nullable();
            $table->decimal('min_value', 10, 3)->nullable();
            $table->decimal('max_value', 10, 3)->nullable();
            $table->string('target_value')->nullable();
            $table->json('options')->nullable();
            $table->string('stage')->default('final_release');
            $table->boolean('required')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('qc_template_items');
    }
};
