<?php

namespace Database\Seeders;

use App\Enums\SizingMode;
use App\Models\Production\ProductCategory;
use App\Models\Production\ProductType;
use Illuminate\Database\Seeder;

class ProductTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = ProductCategory::query()->take(3)->get();

        if ($categories->isEmpty()) {
            return;
        }

        ProductType::updateOrCreate(
            ['slug' => 'soap-bars'],
            [
                'name' => 'Soap Bars',
                'product_category_id' => $categories[0]->id,
                'sizing_mode' => SizingMode::OilWeight,
                'default_batch_size' => 26,
                'expected_units_output' => 288,
                'expected_waste_kg' => 0.8,
                'is_active' => true,
            ]
        );

        ProductType::updateOrCreate(
            ['slug' => 'balms'],
            [
                'name' => 'Balms',
                'product_category_id' => $categories[1]->id ?? $categories[0]->id,
                'sizing_mode' => SizingMode::FinalMass,
                'default_batch_size' => 10,
                'expected_units_output' => 333,
                'unit_fill_size' => 0.030,
                'expected_waste_kg' => 0.2,
                'is_active' => true,
            ]
        );

        ProductType::updateOrCreate(
            ['slug' => 'deodorants'],
            [
                'name' => 'Deodorants',
                'product_category_id' => $categories[2]->id ?? $categories[0]->id,
                'sizing_mode' => SizingMode::FinalMass,
                'default_batch_size' => 12,
                'expected_units_output' => 400,
                'unit_fill_size' => 0.030,
                'expected_waste_kg' => 0.25,
                'is_active' => true,
            ]
        );

    }
}
