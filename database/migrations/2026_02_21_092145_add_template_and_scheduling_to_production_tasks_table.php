<?php

use App\Models\Production\TaskTemplateItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_tasks', function (Blueprint $table) {
            $table->foreignIdFor(TaskTemplateItem::class)->nullable()->after('id')->constrained()->nullOnDelete();
            $table->enum('source', ['template', 'manual'])->default('manual')->after('task_template_item_id');
            $table->date('scheduled_date')->nullable()->after('date');
            $table->timestamp('cancelled_at')->nullable()->after('is_finished');
            $table->text('cancelled_reason')->nullable()->after('cancelled_at');
        });

        Schema::table('production_tasks', function (Blueprint $table) {
            $table->unsignedBigInteger('production_task_type_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('production_tasks', function (Blueprint $table) {
            $table->dropForeign(['task_template_item_id']);
            $table->dropColumn([
                'task_template_item_id',
                'source',
                'scheduled_date',
                'cancelled_at',
                'cancelled_reason',
            ]);
        });
    }
};
