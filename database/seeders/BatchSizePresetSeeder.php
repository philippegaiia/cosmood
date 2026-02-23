<?php

namespace Database\Seeders;

use App\Models\Production\BatchSizePreset;
use App\Models\Production\ProductType;
use Illuminate\Database\Seeder;

class BatchSizePresetSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $productTypes = ProductType::query()->get();

        if ($productTypes->isEmpty()) {
            $productTypes = ProductType::factory()->count(3)->create();
        }

        foreach ($productTypes as $productType) {
            BatchSizePreset::updateOrCreate(
                ['product_type_id' => $productType->id, 'name' => 'Standard'],
                [
                    'batch_size' => $productType->default_batch_size,
                    'expected_units' => $productType->expected_units_output,
                    'expected_waste_kg' => $productType->expected_waste_kg,
                    'is_default' => true,
                ]
            );

            BatchSizePreset::updateOrCreate(
                ['product_type_id' => $productType->id, 'name' => 'Half'],
                [
                    'batch_size' => max(1, $productType->default_batch_size / 2),
                    'expected_units' => max(1, (int) round($productType->expected_units_output / 2)),
                    'expected_waste_kg' => $productType->expected_waste_kg,
                    'is_default' => false,
                ]
            );
        }
    }
}
