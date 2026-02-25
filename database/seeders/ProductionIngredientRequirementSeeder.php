<?php

namespace Database\Seeders;

use App\Models\Production\Production;
use App\Models\Production\ProductionIngredientRequirement;
use App\Models\Supply\Ingredient;
use App\Models\Supply\SupplierListing;
use Illuminate\Database\Seeder;

class ProductionIngredientRequirementSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (ProductionIngredientRequirement::query()->exists()) {
            return;
        }

        $productions = Production::query()->take(12)->get();

        if ($productions->isEmpty()) {
            return;
        }

        $ingredients = Ingredient::query()->get();

        if ($ingredients->isEmpty()) {
            return;
        }

        $listingsByIngredient = SupplierListing::query()->get()->groupBy('ingredient_id');

        foreach ($productions as $production) {
            foreach (range(1, 4) as $index) {
                $ingredient = $ingredients->random();
                $listing = $listingsByIngredient->get($ingredient->id)?->random();

                ProductionIngredientRequirement::factory()->create([
                    'production_id' => $production->id,
                    'production_wave_id' => $production->production_wave_id,
                    'ingredient_id' => $ingredient->id,
                    'supplier_listing_id' => $listing?->id,
                ]);
            }
        }
    }
}
