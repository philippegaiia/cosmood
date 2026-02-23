<?php

use App\Models\Production\TaskTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_template_items', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(TaskTemplate::class)->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->integer('duration_hours')->default(0);
            $table->integer('offset_days')->default(0);
            $table->boolean('skip_weekends')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_template_items');
    }
};
