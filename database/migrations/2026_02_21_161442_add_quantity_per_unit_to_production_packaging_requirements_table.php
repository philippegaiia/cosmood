<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_packaging_requirements', function (Blueprint $table) {
            $table->decimal('quantity_per_unit', 8, 4)->default(1)->after('required_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('production_packaging_requirements', function (Blueprint $table) {
            $table->dropColumn('quantity_per_unit');
        });
    }
};
