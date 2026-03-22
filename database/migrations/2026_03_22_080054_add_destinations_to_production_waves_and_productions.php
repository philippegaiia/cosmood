<?php

use App\Models\Production\Destination;
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
        Schema::table('production_waves', function (Blueprint $table) {
            $table->foreignIdFor(Destination::class, 'default_destination_id')
                ->nullable()
                ->after('slug')
                ->constrained('destinations')
                ->nullOnDelete();
        });

        Schema::table('productions', function (Blueprint $table) {
            $table->foreignIdFor(Destination::class)
                ->nullable()
                ->after('production_wave_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('productions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('destination_id');
        });

        Schema::table('production_waves', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_destination_id');
        });
    }
};
