<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // SQLite doesn't support dropping primary keys, so we need to recreate the table
        $oldData = DB::table('task_template_task_type')->get();

        Schema::dropIfExists('task_template_task_type');

        Schema::create('task_template_task_type', function (Blueprint $table) {
            $table->id();
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
            $table->unique(['task_template_id', 'production_task_type_id']);
            $table->timestamps();
        });

        // Restore the data
        foreach ($oldData as $row) {
            DB::table('task_template_task_type')->insert([
                'task_template_id' => $row->task_template_id,
                'production_task_type_id' => $row->production_task_type_id,
                'sort_order' => $row->sort_order,
                'offset_days' => $row->offset_days,
                'skip_weekends' => $row->skip_weekends,
                'duration_override' => $row->duration_override,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Get current data
        $oldData = DB::table('task_template_task_type')->get();

        Schema::dropIfExists('task_template_task_type');

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

        // Restore the data
        foreach ($oldData as $row) {
            DB::table('task_template_task_type')->insert([
                'task_template_id' => $row->task_template_id,
                'production_task_type_id' => $row->production_task_type_id,
                'sort_order' => $row->sort_order,
                'offset_days' => $row->offset_days,
                'skip_weekends' => $row->skip_weekends,
                'duration_override' => $row->duration_override,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
        }
    }
};
