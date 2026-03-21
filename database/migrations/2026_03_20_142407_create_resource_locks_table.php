<?php

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
        Schema::create('resource_locks', function (Blueprint $table) {
            $table->id();
            $table->morphs('lockable');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('owner_name_snapshot');
            $table->string('token', 64);
            $table->timestamp('acquired_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['lockable_type', 'lockable_id']);
            $table->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('resource_locks');
    }
};
