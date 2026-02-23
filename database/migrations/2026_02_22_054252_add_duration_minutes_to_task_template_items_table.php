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
        Schema::table('task_template_items', function (Blueprint $table) {
            $table->unsignedInteger('duration_minutes')->default(60)->after('name');
        });

        DB::table('task_template_items')->update([
            'duration_minutes' => DB::raw('duration_hours * 60'),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('task_template_items', function (Blueprint $table) {
            $table->dropColumn('duration_minutes');
        });
    }
};
