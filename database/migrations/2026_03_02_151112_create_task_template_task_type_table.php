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
        Schema::create('task_template_task_type', function (Blueprint $table) {
            $table->foreignId('task_template_id')
                ->constrained('task_templates')
                ->cascadeOnDelete();

            $table->foreignId('production_task_type_id')
                ->constrained('production_task_types')
                ->cascadeOnDelete();

            $table->integer('sort_order')->default(0);
            $table->integer('offset_days')->default(0);
            $table->boolean('skip_weekends')->default(true);
            $table->integer('duration_override')->nullable();

            $table->primary(['task_template_id', 'production_task_type_id']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_template_task_type');
    }
};
