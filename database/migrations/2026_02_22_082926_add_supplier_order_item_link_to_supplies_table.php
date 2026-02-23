<?php

use App\Models\Supply\SupplierOrderItem;
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
        Schema::table('supplies', function (Blueprint $table) {
            $table->foreignIdFor(SupplierOrderItem::class)->nullable()->after('supplier_listing_id')->constrained()->nullOnDelete();
            $table->unique('supplier_order_item_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('supplies', function (Blueprint $table) {
            $table->dropUnique(['supplier_order_item_id']);
            $table->dropConstrainedForeignId('supplier_order_item_id');
        });
    }
};
