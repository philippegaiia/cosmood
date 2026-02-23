<?php

use App\Models\Production\Production;
use App\Models\Production\QcTemplateItem;
use App\Models\User;
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
        Schema::create('production_qc_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Production::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(QcTemplateItem::class)->nullable()->constrained()->nullOnDelete();
            $table->string('code')->nullable();
            $table->string('label');
            $table->string('input_type');
            $table->string('unit')->nullable();
            $table->decimal('min_value', 10, 3)->nullable();
            $table->decimal('max_value', 10, 3)->nullable();
            $table->string('target_value')->nullable();
            $table->json('options')->nullable();
            $table->string('stage')->default('final_release');
            $table->boolean('required')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->decimal('value_number', 10, 3)->nullable();
            $table->text('value_text')->nullable();
            $table->boolean('value_boolean')->nullable();
            $table->string('result')->default('pending');
            $table->dateTime('checked_at')->nullable();
            $table->foreignIdFor(User::class, 'checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['production_id', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('production_qc_checks');
    }
};
