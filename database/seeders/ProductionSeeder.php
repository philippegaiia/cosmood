<?php

namespace Database\Seeders;

use App\Models\Production\Formula;
use App\Models\Production\Production;
use App\Models\Production\ProductionWave;
use App\Models\Production\ProductType;
use Illuminate\Database\Seeder;

class ProductionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $formulas = Formula::query()
            ->with(['product'])
            ->whereHas('formulaItems')
            ->get();

        if ($formulas->isEmpty()) {
            return;
        }

        $waves = ProductionWave::query()->get();

        if ($waves->isEmpty()) {
            $waves = ProductionWave::factory()->count(3)->create();
        }

        foreach ($waves as $wave) {
            foreach (range(1, 3) as $index) {
                $formula = $formulas->random();
                $product = $formula->product;

                if (! $product) {
                    continue;
                }

                $productTypeId = $product->product_type_id;

                if (! $productTypeId) {
                    $productTypeId = ProductType::query()
                        ->where('product_category_id', $product->product_category_id)
                        ->value('id');
                }

                Production::factory()->forWave($wave)->create([
                    'product_id' => $product->id,
                    'formula_id' => $formula->id,
                    'product_type_id' => $productTypeId,
                ]);
            }
        }

        foreach (range(1, 4) as $index) {
            $formula = $formulas->random();
            $product = $formula->product;

            if (! $product) {
                continue;
            }

            $productTypeId = $product->product_type_id;

            if (! $productTypeId) {
                $productTypeId = ProductType::query()
                    ->where('product_category_id', $product->product_category_id)
                    ->value('id');
            }

            Production::factory()->orphan()->create([
                'product_id' => $product->id,
                'formula_id' => $formula->id,
                'product_type_id' => $productTypeId,
            ]);
        }
    }
}
