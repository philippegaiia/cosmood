<?php

use App\Models\Production\Production;
use App\Models\Supply\SupplierOrderItem;
use App\Models\Supply\Supply;
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
        Schema::table('supplies_movements', function (Blueprint $table) {
            $table->foreignIdFor(Supply::class)->nullable()->after('id')->constrained()->nullOnDelete();
            $table->foreignIdFor(SupplierOrderItem::class)->nullable()->after('supply_id')->constrained()->nullOnDelete();
            $table->foreignIdFor(Production::class)->nullable()->after('supplier_order_item_id')->constrained()->nullOnDelete();
            $table->foreignIdFor(User::class)->nullable()->after('production_id')->constrained()->nullOnDelete();
            $table->string('movement_type', 20)->nullable()->after('user_id');
            $table->decimal('quantity', 10, 3)->default(0)->after('movement_type');
            $table->string('unit', 10)->default('kg')->after('quantity');
            $table->string('reason')->nullable()->after('unit');
            $table->json('meta')->nullable()->after('reason');
            $table->timestamp('moved_at')->nullable()->after('meta');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('supplies_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supply_id');
            $table->dropConstrainedForeignId('supplier_order_item_id');
            $table->dropConstrainedForeignId('production_id');
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['movement_type', 'quantity', 'unit', 'reason', 'meta', 'moved_at']);
        });
    }
};
