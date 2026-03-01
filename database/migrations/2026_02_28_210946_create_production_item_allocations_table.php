<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_item_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supply_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 10, 3)->default(0);
            $table->enum('status', ['reserved', 'consumed', 'released'])->default('reserved');
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->unique(['production_item_id', 'supply_id']);
            $table->index(['supply_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_item_allocations');
    }
};
