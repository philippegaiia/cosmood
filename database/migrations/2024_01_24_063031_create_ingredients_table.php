<?php

use App\Models\Supply\IngredientCategory;
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
        Schema::create('ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(IngredientCategory::class);
            // ->nullable()
            // ->constrained('ingredient_categories')
            // ->cascadeOnDelete();
            $table->string('name')->required();
            $table->string('code')->required()->unique();
            $table->string('slug')->nullable()->unique();
            $table->string('name_en')->nullable();
            $table->string('inci')->nullable();
            $table->string('inci_naoh')->nullable();
            $table->string('inci_koh')->nullable();
            $table->string('cas')->nullable();
            $table->string('cas_einecs')->nullable();
            $table->string('einecs')->nullable();
            $table->boolean('is_active')->default(true);
            $table->longText('description')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ingredients');
    }
};
