<?php

use App\Models\Production\Production;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_order_items', function (Blueprint $table) {
            $table->foreignIdFor(Production::class, 'allocated_to_production_id')->nullable()->after('is_in_supplies')->constrained('productions')->nullOnDelete();
            $table->decimal('allocated_quantity', 10, 3)->default(0)->after('allocated_to_production_id');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_order_items', function (Blueprint $table) {
            $table->dropForeign(['allocated_to_production_id']);
            $table->dropColumn(['allocated_to_production_id', 'allocated_quantity']);
        });
    }
};
