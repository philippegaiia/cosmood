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
        Schema::table('supplier_order_items', function (Blueprint $table) {
            $table->timestamp('moved_to_stock_at')->nullable()->after('is_in_supplies');
            $table->foreignIdFor(User::class, 'moved_to_stock_by')->nullable()->after('moved_to_stock_at')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('supplier_order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('moved_to_stock_by');
            $table->dropColumn('moved_to_stock_at');
        });
    }
};
