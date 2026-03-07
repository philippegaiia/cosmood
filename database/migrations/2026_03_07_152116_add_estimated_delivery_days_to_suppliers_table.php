<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->unsignedSmallInteger('estimated_delivery_days')
                ->default(8)
                ->after('customer_code');
        });

        DB::table('suppliers')
            ->whereNull('estimated_delivery_days')
            ->update(['estimated_delivery_days' => 8]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('estimated_delivery_days');
        });
    }
};
