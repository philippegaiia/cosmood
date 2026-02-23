<?php

namespace Database\Seeders;

use App\Models\Production\Production;
use App\Models\Production\ProductionItem;
use App\Models\Supply\Ingredient;
use App\Models\Supply\SupplierListing;
use Illuminate\Database\Seeder;

class ProductionItemSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $productions = Production::query()->take(12)->get();

        if ($productions->isEmpty()) {
            $productions = Production::factory()->count(12)->create();
        }

        $ingredients = Ingredient::query()->get();

        if ($ingredients->isEmpty()) {
            $ingredients = Ingredient::factory()->count(20)->create();
        }

        $listingsByIngredient = SupplierListing::query()->get()->groupBy('ingredient_id');

        foreach ($productions as $production) {
            foreach (range(1, 4) as $index) {
                $ingredient = $ingredients->random();
                $listing = $listingsByIngredient->get($ingredient->id)?->random();

                ProductionItem::factory()->create([
                    'production_id' => $production->id,
                    'ingredient_id' => $ingredient->id,
                    'supplier_listing_id' => $listing?->id ?? SupplierListing::factory()->create(['ingredient_id' => $ingredient->id])->id,
                ]);
            }
        }
    }
}
