<?php

use App\Models\Supply\Supplier;
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
        Schema::create('supplier_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Supplier::class)->constrained()->nullable();
            $table->unsignedSmallInteger('serial_number');
            $table->unsignedTinyInteger('order_status');
            $table->string('order_ref')->nullable();
            $table->date('order_date')->default(now());
            $table->date('delivery_date')->nullable();
            $table->string('confirmation_number')->nullable();
            $table->string('invoice_number')->nullable();
            $table->string('bl_number')->nullable();
            $table->decimal('freight_cost', 6, 2)->nullable();
            $table->longText('description')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_orders');
    }
};
