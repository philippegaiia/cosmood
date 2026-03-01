<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('production_ingredient_requirements');
    }

    public function down(): void
    {
        // The production_ingredient_requirements table has been removed
        // and replaced by production_items with allocations
    }
};
