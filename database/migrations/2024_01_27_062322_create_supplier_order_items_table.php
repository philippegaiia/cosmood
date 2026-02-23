<?php

use App\Models\Supply\SupplierListing;
use App\Models\Supply\SupplierOrder;
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
        Schema::create('supplier_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(SupplierOrder::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(SupplierListing::class)->constrained()->cascadeOnDelete();
            $table->decimal('unit_weight', 7, 3)->nullable();
            $table->decimal('quantity', 10, 3);
            $table->decimal('unit_price', 7, 2)->nullable();
            $table->string('batch_number')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('is_in_supplies');
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_order_items');
    }
};
