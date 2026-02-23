<?php

use App\Models\Supply\Supply;
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
        Schema::table('production_items', function (Blueprint $table) {
            $table->foreignId('supplier_listing_id')->nullable()->change();
            $table->foreignIdFor(Supply::class)->nullable()->after('supplier_listing_id')->constrained()->nullOnDelete();
            $table->string('supply_batch_number')->nullable()->after('supply_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('production_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supply_id');
            $table->dropColumn('supply_batch_number');
        });
    }
};
