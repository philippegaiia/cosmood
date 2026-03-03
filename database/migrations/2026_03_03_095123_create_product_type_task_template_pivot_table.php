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
        Schema::create('product_type_task_template', function (Blueprint $table) {
            $table->foreignId('product_type_id')
                ->constrained('product_types')
                ->cascadeOnDelete();
            $table->foreignId('task_template_id')
                ->constrained('task_templates')
                ->cascadeOnDelete();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->primary(['product_type_id', 'task_template_id']);
        });

        // Migrate existing data from task_templates table
        $existingData = DB::table('task_templates')
            ->whereNotNull('product_type_id')
            ->select('product_type_id', 'id as task_template_id', 'is_default', 'created_at', 'updated_at')
            ->get();

        foreach ($existingData as $row) {
            DB::table('product_type_task_template')->insert([
                'product_type_id' => $row->product_type_id,
                'task_template_id' => $row->task_template_id,
                'is_default' => $row->is_default,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
        }

        // Remove product_type_id and is_default columns from task_templates
        Schema::table('task_templates', function (Blueprint $table) {
            $table->dropForeign(['product_type_id']);
            $table->dropColumn(['product_type_id', 'is_default']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Add back columns to task_templates
        Schema::table('task_templates', function (Blueprint $table) {
            $table->foreignId('product_type_id')
                ->nullable()
                ->constrained('product_types')
                ->cascadeOnDelete();
            $table->boolean('is_default')->default(false);
        });

        // Migrate data back
        $pivotData = DB::table('product_type_task_template')->get();
        foreach ($pivotData as $row) {
            DB::table('task_templates')
                ->where('id', $row->task_template_id)
                ->update([
                    'product_type_id' => $row->product_type_id,
                    'is_default' => $row->is_default,
                ]);
        }

        Schema::dropIfExists('product_type_task_template');
    }
};
