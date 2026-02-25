<?php

use App\Models\Production\QcTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_types', function (Blueprint $table): void {
            $table->foreignIdFor(QcTemplate::class)
                ->nullable()
                ->after('product_category_id')
                ->constrained()
                ->nullOnDelete();
        });

        $templateByProductType = DB::table('qc_templates')
            ->whereNotNull('product_type_id')
            ->orderByDesc('is_default')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('product_type_id')
            ->map(fn ($templates): int => (int) $templates->first()->id);

        foreach ($templateByProductType as $productTypeId => $templateId) {
            DB::table('product_types')
                ->where('id', (int) $productTypeId)
                ->update([
                    'qc_template_id' => $templateId,
                ]);
        }

        Schema::table('qc_templates', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('product_type_id');
        });
    }

    public function down(): void
    {
        Schema::table('qc_templates', function (Blueprint $table): void {
            $table->foreignId('product_type_id')
                ->nullable()
                ->after('id')
                ->constrained('product_types')
                ->nullOnDelete();
        });

        $productTypes = DB::table('product_types')
            ->whereNotNull('qc_template_id')
            ->orderBy('id')
            ->get(['id', 'qc_template_id']);

        foreach ($productTypes as $productType) {
            DB::table('qc_templates')
                ->where('id', (int) $productType->qc_template_id)
                ->whereNull('product_type_id')
                ->update([
                    'product_type_id' => (int) $productType->id,
                ]);
        }

        Schema::table('product_types', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('qc_template_id');
        });
    }
};
