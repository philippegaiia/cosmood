<?php

use App\Models\User;
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
        Schema::table('production_tasks', function (Blueprint $table) {
            $table->timestamp('dependency_bypassed_at')->nullable()->after('is_manual_schedule');
            $table->foreignIdFor(User::class, 'dependency_bypassed_by')->nullable()->after('dependency_bypassed_at')->constrained('users')->nullOnDelete();
            $table->text('dependency_bypass_reason')->nullable()->after('dependency_bypassed_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('production_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dependency_bypassed_by');
            $table->dropColumn(['dependency_bypassed_at', 'dependency_bypass_reason']);
        });
    }
};
