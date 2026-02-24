<?php

use App\Models\Production\Production;
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
            $table->foreignIdFor(Production::class, 'source_production_id')
                ->nullable()
                ->after('supplier_order_item_id')
                ->constrained('productions')
                ->nullOnDelete();

            $table->unique('source_production_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('supplies', function (Blueprint $table) {
            $table->dropUnique(['source_production_id']);
            $table->dropConstrainedForeignId('source_production_id');
        });
    }
};
